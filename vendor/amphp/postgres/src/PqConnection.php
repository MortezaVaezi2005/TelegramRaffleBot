<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Sql\SqlConnectionException;
use pq;
use Revolt\EventLoop;

final class PqConnection extends Internal\PostgresHandleConnection implements PostgresConnection
{
    private readonly Internal\PqHandle $handle;

    public static function connect(PostgresConfig $config, ?Cancellation $cancellation = null): self
    {
        try {
            $connection = new pq\Connection($config->getConnectionString(), pq\Connection::ASYNC);
        } catch (pq\Exception $exception) {
            throw new SqlConnectionException("Could not connect to PostgreSQL server", 0, $exception);
        }

        $connection->nonblocking = true;
        $connection->unbuffered = true;

        $deferred = new DeferredFuture();

        /** @psalm-suppress UndefinedVariable $poll is defined below. */
        $callback = static function () use (&$poll, &$await, $connection, $config, $deferred): void {
            switch ($result = $connection->poll()) {
                case pq\Connection::POLLING_READING:
                case pq\Connection::POLLING_WRITING:
                    return; // Connection still reading or writing, so return and leave callback enabled.

                case pq\Connection::POLLING_FAILED:
                    $deferred->error(new SqlConnectionException($connection->errorMessage));
                    break;

                case pq\Connection::POLLING_OK:
                    $deferred->complete(new self($connection, $config));
                    break;

                default:
                    $deferred->error(new SqlConnectionException('Unexpected connection status value: ' . $result));
                    break;
            }

            EventLoop::disable($poll);
            EventLoop::disable($await);
        };

        $poll = EventLoop::onReadable($connection->socket, $callback);
        $await = EventLoop::onWritable($connection->socket, $callback);

        try {
            return $deferred->getFuture()->await($cancellation);
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($await);
        }
    }

    protected function __construct(pq\Connection $handle, PostgresConfig $config)
    {
        $this->handle = new Internal\PqHandle($handle, $config);
        parent::__construct($this->handle);
    }

    /**
     * @return bool True if result sets are buffered in memory, false if unbuffered.
     */
    public function isBufferingResults(): bool
    {
        return $this->handle->isBufferingResults();
    }

    /**
     * Sets result sets to be fully buffered in local memory.
     */
    public function shouldBufferResults(): void
    {
        $this->handle->shouldBufferResults();
    }

    /**
     * Sets result sets to be streamed from the database server.
     */
    public function shouldNotBufferResults(): void
    {
        $this->handle->shouldNotBufferResults();
    }
}
