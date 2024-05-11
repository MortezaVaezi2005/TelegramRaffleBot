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
use pq;
use Revolt\EventLoop;
use function Amp\async;

/** @internal  */
final class PqHandle extends AbstractHandle
{
    private ?pq\Connection $handle;

    private ?DeferredFuture $busy = null;

    /** @var array<non-empty-string, StatementStorage<pq\Statement>> */
    private array $statements = [];

    public function __construct(pq\Connection $handle, PostgresConfig $config)
    {
        $this->handle = $handle;

        $handle = &$this->handle;
        $lastUsedAt = &$this->lastUsedAt;
        $deferred = &$this->pendingOperation;
        $listeners = &$this->listeners;
        $onClose = new DeferredFuture();

        $poll = EventLoop::onReadable($this->handle->socket, static function (string $watcher) use (
            &$deferred,
            &$lastUsedAt,
            &$listeners,
            &$handle,
            $onClose,
        ): void {
            $lastUsedAt = \time();

            try {
                if ($handle->status !== pq\Connection::OK) {
                    throw new SqlConnectionException("The connection closed during the operation");
                }

                if ($handle->poll() === pq\Connection::POLLING_FAILED) {
                    throw new SqlConnectionException($handle->errorMessage);
                }
            } catch (SqlConnectionException $exception) {
                $handle = null; // Marks connection as dead.
                EventLoop::disable($watcher);

                self::shutdown($listeners, $deferred, $onClose, $exception);

                return;
            }

            if ($deferred === null) {
                return; // No active query, only notification listeners.
            }

            if ($handle->busy) {
                return; // Not finished receiving data, poll again.
            }

            $deferred->complete($handle->getResult());
            $deferred = null;

            if (empty($listeners)) {
                EventLoop::unreference($watcher);
            }
        });

        $await = EventLoop::onWritable($this->handle->socket, static function (string $watcher) use (
            &$deferred,
            &$listeners,
            &$handle,
            $onClose,
        ): void {
            try {
                if (!$handle?->flush()) {
                    return; // Not finished sending data, continue polling for writability.
                }
            } catch (pq\Exception $exception) {
                $exception = new SqlConnectionException("Flushing the connection failed", 0, $exception);
                $handle = null; // Marks connection as dead.

                self::shutdown($listeners, $deferred, $onClose, $exception);
            }

            EventLoop::disable($watcher);
        });

        EventLoop::unreference($poll);
        EventLoop::disable($await);

        parent::__construct($config, $poll, $await, $onClose);
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    public function close(): void
    {
        $this->handle = null;
        parent::close();
    }

    /**
     * @param string|null Query SQL or null if not related.
     * @param \Closure $method Method to execute.
     * @param mixed ...$args Arguments to pass to function.
     *
     * @throws SqlException
     */
    private function send(?string $sql, \Closure $method, mixed ...$args): mixed
    {
        while ($this->busy) {
            try {
                $this->busy->getFuture()->await();
            } catch (\Throwable) {
                // Ignore failure from another operation.
            }
        }

        if (!$this->handle) {
            throw new SqlConnectionException("The connection to the database has been closed");
        }

        try {
            $this->pendingOperation = $this->busy = new DeferredFuture;

            $handle = $method(...$args);

            EventLoop::reference($this->poll);
            if (!$this->handle->flush()) {
                EventLoop::enable($this->await);
            }

            $result = $this->pendingOperation->getFuture()->await();
        } catch (pq\Exception $exception) {
            throw new SqlException($this->handle->errorMessage, 0, $exception);
        } finally {
            $this->busy = null;
        }

        if (!$result instanceof pq\Result) {
            throw new SqlException("Unknown query result: " . \get_debug_type($result));
        }

        if ($handle instanceof pq\Statement) {
            switch ($result->status) {
                case pq\Result::COMMAND_OK:
                    return $handle; // Will be wrapped into a ConnectionStatement object.

                default:
                    $this->makeResult($result, $sql);
                    // The statement below *should* be unreachable: $this->makeResult() will throw.
                    throw new SqlException("Unexpected error preparing statement: " . $result->errorMessage);
            }
        }

        return $this->makeResult($result, $sql);
    }

    /**
     * @throws SqlException
     */
    private function makeResult(pq\Result $result, ?string $sql): PostgresResult
    {
        if (!$this->handle) {
            throw new SqlConnectionException("Connection closed");
        }

        switch ($result->status) {
            case pq\Result::EMPTY_QUERY:
                throw new SqlQueryError("Empty query string");

            case pq\Result::COMMAND_OK:
                return new PostgresCommandResult(
                    $result->affectedRows,
                    Future::complete($this->fetchNextResult($sql)),
                );

            case pq\Result::TUPLES_OK:
                return new PqBufferedResultSet($result, Future::complete($this->fetchNextResult($sql)));

            case pq\Result::SINGLE_TUPLE:
                $this->busy = new DeferredFuture;
                return new PqUnbufferedResultSet(
                    fn () => $this->fetch($sql),
                    $result,
                    $this->busy->getFuture()
                );

            case pq\Result::NONFATAL_ERROR:
            case pq\Result::FATAL_ERROR:
                while ($this->handle->busy && $this->handle->getResult()) {
                    // Clear all outstanding result rows from the connection
                }
                throw new PostgresQueryError($result->errorMessage, $result->diag, $sql ?? '');

            case pq\Result::BAD_RESPONSE:
                $this->close();
                throw new SqlException($result->errorMessage);

            default:
                $this->close();
                throw new SqlException("Unknown result status");
        }
    }

    /**
     * @throws SqlException
     */
    private function fetchNextResult(?string $sql): ?PostgresResult
    {
        if (!$this->handle) {
            throw new SqlConnectionException("Connection closed");
        }

        if (!$this->handle->busy && ($next = $this->handle->getResult()) instanceof pq\Result) {
            return $this->makeResult($next, $sql);
        }

        return null;
    }

    private function fetch(?string $sql): ?pq\Result
    {
        if (!$this->handle) {
            throw new SqlConnectionException("Connection closed");
        }

        if (!$this->handle->busy) { // Results buffered.
            $result = $this->handle->getResult();
        } else {
            $this->pendingOperation = new DeferredFuture;

            EventLoop::reference($this->poll);
            if (!$this->handle->flush()) {
                EventLoop::enable($this->await);
            }

            $result = $this->pendingOperation->getFuture()->await();
        }

        if (!$result) {
            throw new SqlConnectionException("Connection closed");
        }

        switch ($result->status) {
            case pq\Result::TUPLES_OK: // End of result set.
                $deferred = $this->busy;
                $this->busy = null;

                \assert($deferred !== null, 'Pending deferred was not set');

                try {
                    $deferred->complete($this->fetchNextResult($sql));
                } catch (\Throwable $exception) {
                    $deferred->error($exception);
                }

                return null;

            case pq\Result::SINGLE_TUPLE:
                return $result;

            default:
                $this->close();
                throw new SqlException($result->errorMessage);
        }
    }

    /**
     * Executes the named statement using the given parameters.
     *
     * @throws SqlException
     */
    public function statementExecute(string $name, array $params): PostgresResult
    {
        \assert(isset($this->statements[$name]), "Named statement not found when executing");

        $storage = $this->statements[$name];

        $statement = $storage->future->await();
        if (!$statement instanceof pq\Statement) {
            throw new SqlException('Statement unexpectedly closed before being executed');
        }

        return $this->send(
            $storage->sql,
            $statement->execAsync(...),
            \array_map(cast(...), $this->escapeParams($params)),
        );
    }

    /**
     * @throws SqlException
     */
    public function statementDeallocate(string $name): void
    {
        if (!$this->handle) {
            return; // Connection dead.
        }

        \assert(isset($this->statements[$name]), "Named statement not found when deallocating");

        $storage = $this->statements[$name];

        if (--$storage->refCount) {
            return;
        }

        $future = $storage->future;
        $storage->future = async(function () use ($future, $storage, $name): void {
            $statement = $future->await();
            if (!$statement instanceof pq\Statement) {
                return; // Statement already deallocated.
            }

            $this->send(null, $statement->deallocateAsync(...));
            unset($this->statements[$name]);
        });
    }

    public function escapeByteA(string $data): string
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->handle->escapeBytea($data);
    }

    public function query(string $sql): PostgresResult
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->send($sql, $this->handle->execAsync(...), $sql);
    }

    public function execute(string $sql, array $params = []): PostgresResult
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        $sql = parseNamedParams($sql, $names);
        $params = replaceNamedParams($params, $names);

        return $this->send(
            $sql,
            $this->handle->execParamsAsync(...),
            $sql,
            \array_map(cast(...), $this->escapeParams($params)),
        );
    }

    public function prepare(string $sql): PostgresStatement
    {
        if (!$this->handle) {
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

        $future = async(function () use ($sql, $name, $modifiedSql): pq\Statement {
            if (!$this->handle) {
                throw new \Error("The connection to the database has been closed");
            }

            return $this->send($sql, $this->handle->prepareAsync(...), $name, $modifiedSql);
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
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->send(null, $this->handle->notifyAsync(...), $channel, $payload);
    }

    public function listen(string $channel): PostgresListener
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        if (isset($this->listeners[$channel])) {
            throw new SqlQueryError(\sprintf("Already listening on channel '%s'", $channel));
        }

        $this->listeners[$channel] = $source = new Queue();

        try {
            $this->send(
                null,
                $this->handle->listenAsync(...),
                $channel,
                /**
                 * @param non-empty-string $channel
                 * @param positive-int $pid
                 */
                static function (string $channel, string $message, int $pid) use ($source): void {
                    $notification = new PostgresNotification($channel, $pid, $message);
                    $source->pushAsync($notification)->ignore();
                }
            );
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

        if (!$this->handle) {
            $source->complete();
            return; // Connection already closed.
        }

        try {
            $this->send(null, $this->handle->unlistenAsync(...), $channel);
            $source->complete();
        } catch (\Throwable $exception) {
            $source->error($exception);
        }
    }

    public function quoteLiteral(string $data): string
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->handle->quote($data);
    }

    public function quoteIdentifier(string $name): string
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return $this->handle->quoteName($name);
    }

    /**
     * @return bool True if result sets are buffered in memory, false if unbuffered.
     */
    public function isBufferingResults(): bool
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        return !$this->handle->unbuffered;
    }

    /**
     * Sets result sets to be fully buffered in local memory.
     */
    public function shouldBufferResults(): void
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        $this->handle->unbuffered = false;
    }

    /**
     * Sets result sets to be streamed from the database server.
     */
    public function shouldNotBufferResults(): void
    {
        if (!$this->handle) {
            throw new \Error("The connection to the database has been closed");
        }

        $this->handle->unbuffered = true;
    }
}
