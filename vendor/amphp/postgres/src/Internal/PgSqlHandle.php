<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Queue;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresNotification;
use Amp\Postgres\PostgresQueryError;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Revolt\EventLoop;
use function Amp\async;

/** @internal  */
final class PgSqlHandle extends AbstractHandle
{
    private const DIAGNOSTIC_CODES = [
        \PGSQL_DIAG_SEVERITY => "severity",
        \PGSQL_DIAG_SQLSTATE => "sqlstate",
        \PGSQL_DIAG_MESSAGE_PRIMARY => "message_primary",
        \PGSQL_DIAG_MESSAGE_DETAIL => "message_detail",
        \PGSQL_DIAG_MESSAGE_HINT => "message_hint",
        \PGSQL_DIAG_STATEMENT_POSITION => "statement_position",
        \PGSQL_DIAG_INTERNAL_POSITION => "internal_position",
        \PGSQL_DIAG_INTERNAL_QUERY => "internal_query",
        \PGSQL_DIAG_CONTEXT => "context",
        \PGSQL_DIAG_SOURCE_FILE => "source_file",
        \PGSQL_DIAG_SOURCE_LINE => "source_line",
        \PGSQL_DIAG_SOURCE_FUNCTION => "source_function",
    ];

    /** @var array<string, array<int, PgSqlType>> */
    private static array $typeCache;

    private static ?\Closure $errorHandler = null;

    /** @var \PgSql\Connection PostgreSQL connection handle. */
    private ?\PgSql\Connection $handle;

    /** @var array<int, PgSqlType> */
    private readonly array $types;

    /** @var array<non-empty-string, StatementStorage<string>> */
    private array $statements = [];

    /**
     * @param \PgSql\Connection $handle PostgreSQL connection handle.
     * @param resource $socket PostgreSQL connection stream socket.
     * @param string $id Connection identifier for determining which cached type table to use.
     */
    public function __construct(
        \PgSql\Connection $handle,
        $socket,
        string $id,
        PostgresConfig $config,
    ) {
        $this->handle = $handle;

        $this->types = (self::$typeCache[$id] ??= self::fetchTypes($handle));

        $handle = &$this->handle;
        $lastUsedAt = &$this->lastUsedAt;
        $deferred = &$this->pendingOperation;
        $listeners = &$this->listeners;
        $onClose = new DeferredFuture();

        $poll = EventLoop::onReadable($socket, static function (string $watcher) use (
            &$deferred,
            &$lastUsedAt,
            &$listeners,
            &$handle,
            $onClose,
        ): void {
            if (!$handle) {
                EventLoop::disable($watcher);
                return;
            }

            $lastUsedAt = \time();

            \set_error_handler(self::getErrorHandler());

            try {
                if (\pg_connection_status($handle) !== \PGSQL_CONNECTION_OK) {
                    throw new SqlConnectionException("The connection closed during the operation");
                }

                if (!\pg_consume_input($handle)) {
                    throw new SqlConnectionException(\pg_last_error($handle));
                }

                while ($result = \pg_get_notify($handle, \PGSQL_ASSOC)) {
                    $channel = $result["message"];

                    if (!isset($listeners[$channel])) {
                        continue;
                    }

                    $notification = new PostgresNotification($channel, $result["pid"], $result["payload"]);
                    $listeners[$channel]->pushAsync($notification)->ignore();
                }

                if ($deferred === null) {
                    return; // No active query, only notification listeners.
                }

                if (\pg_connection_busy($handle)) {
                    return;
                }

                $deferred->complete(\pg_get_result($handle));
                $deferred = null;

                if (empty($listeners)) {
                    EventLoop::unreference($watcher);
                }
            } catch (SqlConnectionException $exception) {
                $handle = null; // Marks connection as dead.
                EventLoop::disable($watcher);

                self::shutdown($listeners, $deferred, $onClose, $exception);
            } finally {
                \restore_error_handler();
            }
        });

        $await = EventLoop::onWritable($socket, static function (string $watcher) use (
            &$deferred,
            &$listeners,
            &$handle,
            $onClose,
        ): void {
            if (!$handle) {
                EventLoop::disable($watcher);
                return;
            }

            \set_error_handler(self::getErrorHandler());

            try {
                $flush = \pg_flush($handle);
                if ($flush === 0) {
                    return; // Not finished sending data, listen again.
                }

                EventLoop::disable($watcher);

                if ($flush === false) {
                    throw new SqlConnectionException(\pg_last_error($handle));
                }
            } catch (SqlConnectionException $exception) {
                $handle = null; // Marks connection as dead.
                EventLoop::disable($watcher);

                self::shutdown($listeners, $deferred, $onClose, $exception);
            } finally {
                \restore_error_handler();
            }
        });

        EventLoop::unreference($poll);
        EventLoop::disable($await);

        parent::__construct($config, $poll, $await, $onClose);
    }

    /**
     * @return array<int, PgSqlType>
     */
    private static function fetchTypes(\PgSql\Connection $handle): array
    {
        $result = \pg_query($handle, "SELECT t.oid, t.typcategory, t.typdelim, t.typelem
             FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON t.typnamespace=n.oid
             WHERE t.typisdefined AND n.nspname IN ('pg_catalog', 'public') ORDER BY t.oid");

        if ($result === false) {
            throw new SqlException(\pg_last_error($handle));
        }

        $types = [];
        while ($row = \pg_fetch_array($result, mode: \PGSQL_NUM)) {
            [$oid, $type, $delimiter, $element] = $row;
            \assert(\is_numeric($oid) && \is_numeric($element), "OID and element type expected to be integers");
            \assert(\is_string($type) && \is_string($delimiter), "Unexpected types in type catalog query results");
            $types[(int) $oid] = new PgSqlType($type, $delimiter, (int) $element);
        }

        return $types;
    }

    private static function getErrorHandler(): \Closure
    {
        return self::$errorHandler ??= static function (int $code, string $message): never {
            throw new SqlConnectionException($message, $code);
        };
    }

    public function close(): void
    {
        $this->handle = null;
        parent::close();
    }

    public function isClosed(): bool
    {
        return !$this->handle instanceof \PgSql\Connection;
    }

    /**
     * @param \Closure $function Function to execute.
     * @param mixed ...$args Arguments to pass to function.
     *
     * @return \PgSql\Result
     *
     * @throws SqlException
     */
    private function send(\Closure $function, mixed ...$args): mixed
    {
        while ($this->pendingOperation) {
            try {
                $this->pendingOperation->getFuture()->await();
            } catch (\Throwable) {
                // Ignore failure from another operation.
            }
        }

        if ($this->handle === null) {
            throw new SqlConnectionException("The connection to the database has been closed");
        }

        while ($result = \pg_get_result($this->handle)) {
            \pg_free_result($result);
        }

        $result = $function($this->handle, ...$args);

        if ($result === false) {
            throw new SqlException(\pg_last_error($this->handle));
        }

        $this->pendingOperation = new DeferredFuture;

        EventLoop::reference($this->poll);
        if ($result === 0) {
            EventLoop::enable($this->await);
        }

        return $this->pendingOperation->getFuture()->await();
    }

    /**
     * @param \PgSql\Result $result PostgreSQL result resource.
     * @param string $sql Query SQL.
     *
     * @throws SqlException
     * @throws SqlQueryError
     */
    private function createResult(\PgSql\Result $result, string $sql): PostgresResult
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        switch (\pg_result_status($result)) {
            case \PGSQL_EMPTY_QUERY:
                throw new SqlQueryError("Empty query string");

            case \PGSQL_COMMAND_OK:
                return new PostgresCommandResult(
                    \pg_affected_rows($result),
                    Future::complete($this->fetchNextResult($sql)),
                );

            case \PGSQL_TUPLES_OK:
                return new PgSqlResultSet($result, $this->types, Future::complete($this->fetchNextResult($sql)));

            case \PGSQL_NONFATAL_ERROR:
            case \PGSQL_FATAL_ERROR:
                $diagnostics = [];
                foreach (self::DIAGNOSTIC_CODES as $fieldCode => $description) {
                    $diagnostics[$description] = \pg_result_error_field($result, $fieldCode);
                }
                $message = \pg_result_error($result);
                \set_error_handler(self::getErrorHandler());
                try {
                    while (\pg_connection_busy($this->handle) && \pg_get_result($this->handle)) {
                        // Clear all outstanding result rows from the connection
                    }
                } finally {
                    \restore_error_handler();
                    throw new PostgresQueryError($message, $diagnostics, $sql);
                }

            case \PGSQL_BAD_RESPONSE:
                $this->close();
                throw new SqlException(\pg_result_error($result));

            default:
                // @codeCoverageIgnoreStart
                $this->close();
                throw new SqlException("Unknown result status");
                // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @throws SqlException
     */
    private function fetchNextResult(string $sql): ?PostgresResult
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        if ($result = \pg_get_result($this->handle)) {
            return $this->createResult($result, $sql);
        }

        return null;
    }

    public function statementExecute(string $name, array $params): PostgresResult
    {
        \assert(isset($this->statements[$name]), "Named statement not found when executing");
        $result = $this->send(\pg_send_execute(...), $name, \array_map(cast(...), $this->escapeParams($params)));
        return $this->createResult($result, $this->statements[$name]->sql);
    }

    /**
     * @throws \Error
     */
    public function statementDeallocate(string $name): void
    {
        if ($this->isClosed()) {
            return; // Connection closed, no need to deallocate.
        }

        \assert(isset($this->statements[$name]), "Named statement not found when deallocating");

        $storage = $this->statements[$name];

        if (--$storage->refCount) {
            return;
        }

        $future = $storage->future;
        $storage->future = async(function () use ($future, $storage, $name): void {
            if (!$future->await()) {
                return; // Statement already deallocated.
            }

            $this->query(\sprintf("DEALLOCATE %s", $name));
            unset($this->statements[$name]);
        });
        $storage->future->ignore();
    }

    public function escapeByteA(string $data): string
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        return \pg_escape_bytea($this->handle, $data);
    }

    public function query(string $sql): PostgresResult
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->createResult($this->send(\pg_send_query(...), $sql), $sql);
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        $sql = parseNamedParams($sql, $names);
        $params = replaceNamedParams($params, $names);

        $result = $this->send(
            \pg_send_query_params(...),
            $sql,
            \array_map(cast(...), $this->escapeParams($params))
        );

        return $this->createResult($result, $sql);
    }

    public function prepare(string $sql): PostgresStatement
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        $modifiedSql = parseNamedParams($sql, $names);

        $name = self::STATEMENT_NAME_PREFIX . \sha1($modifiedSql);

        while (isset($this->statements[$name])) {
            $storage = $this->statements[$name];

            ++$storage->refCount;
            // Do not return promised prepared statement object, as the $names array may differ.
            $result = $storage->future->await();

            if ($result) { // Null returned if future was from deallocation.
                return new PostgresConnectionStatement($this, $name, $sql, $names);
            }
        }

        $future = async(function () use ($name, $modifiedSql, $sql): string {
            $result = $this->send(\pg_send_prepare(...), $name, $modifiedSql);

            switch (\pg_result_status($result, \PGSQL_STATUS_LONG)) {
                case \PGSQL_COMMAND_OK:
                    return $name; // Statement created successfully.

                case \PGSQL_NONFATAL_ERROR:
                case \PGSQL_FATAL_ERROR:
                    $diagnostics = [];
                    foreach (self::DIAGNOSTIC_CODES as $fieldCode => $description) {
                        $diagnostics[$description] = \pg_result_error_field($result, $fieldCode);
                    }
                    throw new PostgresQueryError(\pg_result_error($result), $diagnostics, $sql);

                case \PGSQL_BAD_RESPONSE:
                    throw new SqlException(\pg_result_error($result));

                default:
                    // @codeCoverageIgnoreStart
                    throw new SqlException("Unknown result status");
                    // @codeCoverageIgnoreEnd
            }
        });

        $storage = new StatementStorage($sql, $future);
        $this->statements[$name] = $storage;

        try {
            $storage->future->await();
        } catch (\Throwable $exception) {
            unset($this->statements[$name]);
            throw $exception;
        }

        return new PostgresConnectionStatement($this, $name, $sql, $names);
    }

    public function notify(string $channel, string $payload = ""): PostgresResult
    {
        if ($payload === "") {
            return $this->query(\sprintf("NOTIFY %s", $this->quoteIdentifier($channel)));
        }

        return $this->query(\sprintf("NOTIFY %s, %s", $this->quoteIdentifier($channel), $this->quoteLiteral($payload)));
    }

    public function listen(string $channel): PostgresListener
    {
        if (isset($this->listeners[$channel])) {
            throw new SqlQueryError(\sprintf("Already listening on channel '%s'", $channel));
        }

        $this->listeners[$channel] = $source = new Queue();

        try {
            $this->query(\sprintf("LISTEN %s", $this->quoteIdentifier($channel)));
        } catch (\Throwable $exception) {
            unset($this->listeners[$channel]);
            throw $exception;
        }

        EventLoop::enable($this->poll);
        return new PostgresConnectionListener($source->iterate(), $channel, $this->unlisten(...));
    }

    /**
     * @throws \Error
     */
    private function unlisten(string $channel): void
    {
        if (!isset($this->listeners[$channel])) {
            return;
        }

        $source = $this->listeners[$channel];
        unset($this->listeners[$channel]);

        if ($this->handle === null) {
            $source->complete();
            return; // Connection already closed.
        }

        try {
            $this->query(\sprintf("UNLISTEN %s", $this->quoteIdentifier($channel)));
            $source->complete();
        } catch (\Throwable $exception) {
            $source->error($exception);
        }
    }

    public function quoteLiteral(string $data): string
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        return \pg_escape_literal($this->handle, $data);
    }

    public function quoteIdentifier(string $name): string
    {
        if ($this->handle === null) {
            throw new \Error("The connection to the database has been closed");
        }

        return \pg_escape_identifier($this->handle, $name);
    }
}
