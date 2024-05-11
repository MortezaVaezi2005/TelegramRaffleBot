<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Sql\SqlConnectionException;
use Revolt\EventLoop;

final class PgSqlConnection extends Internal\PostgresHandleConnection implements PostgresConnection
{
    /**
     * @throws \Error If pecl-ev is used as a loop extension.
     */
    public static function connect(PostgresConfig $config, ?Cancellation $cancellation = null): self
    {
        // @codeCoverageIgnoreStart
        /** @psalm-suppress UndefinedClass */
        if (EventLoop::getDriver()->getHandle() instanceof \EvLoop) {
            throw new \Error('ext-pgsql is not compatible with pecl-ev; use pecl-pq or a different loop extension');
        } // @codeCoverageIgnoreEnd

        if (!$connection = \pg_connect($config->getConnectionString(), \PGSQL_CONNECT_ASYNC | \PGSQL_CONNECT_FORCE_NEW)) {
            throw new SqlConnectionException("Failed to create connection resource");
        }

        if (\pg_connection_status($connection) === \PGSQL_CONNECTION_BAD) {
            throw new SqlConnectionException(\pg_last_error($connection));
        }

        if (!$socket = \pg_socket($connection)) {
            throw new SqlConnectionException("Failed to access connection socket");
        }

        $hash = \sha1($config->getHost() . $config->getPort() . $config->getUser());

        $deferred = new DeferredFuture();

        /**
         * @psalm-suppress MissingClosureParamType $resource is a resource and cannot be inferred in this context.
         * @psalm-suppress UndefinedVariable $poll is defined below.
         */
        $callback = static function (string $callbackId, $resource) use (
            &$poll,
            &$await,
            $connection,
            $config,
            $deferred,
            $hash,
        ): void {
            switch ($result = \pg_connect_poll($connection)) {
                case \PGSQL_POLLING_READING:
                case \PGSQL_POLLING_WRITING:
                    return; // Connection still reading or writing, so return and leave callback enabled.

                case \PGSQL_POLLING_FAILED:
                    $deferred->error(new SqlConnectionException(\pg_last_error($connection)));
                    break;

                case \PGSQL_POLLING_OK:
                    $deferred->complete(new self($connection, $resource, $hash, $config));
                    break;

                default:
                    $deferred->error(new SqlConnectionException('Unexpected connection status value: ' . $result));
                    break;
            }

            EventLoop::disable($poll);
            EventLoop::disable($await);
        };

        $poll = EventLoop::onReadable($socket, $callback);
        $await = EventLoop::onWritable($socket, $callback);

        try {
            return $deferred->getFuture()->await($cancellation);
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($await);
        }
    }

    /**
     * @param \PgSql\Connection $handle PostgreSQL connection handle.
     * @param resource $socket PostgreSQL connection stream socket.
     * @param string $id Connection identifier for determining which cached type table to use.
     */
    protected function __construct(\PgSql\Connection $handle, $socket, string $id, PostgresConfig $config)
    {
        parent::__construct(new Internal\PgSqlHandle($handle, $socket, $id, $config));
    }
}
