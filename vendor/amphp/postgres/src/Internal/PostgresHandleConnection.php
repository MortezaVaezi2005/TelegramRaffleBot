<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Postgres\Internal;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnection;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;

/** @internal */
abstract class PostgresHandleConnection implements PostgresConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture|null Used to only allow one transaction at a time. */
    private ?DeferredFuture $busy = null;

    private SqlTransactionIsolation $transactionIsolation = SqlTransactionIsolationLevel::Committed;

    /**
     * @throws SqlConnectionException
     */
    abstract public static function connect(
        PostgresConfig $config,
        ?Cancellation $cancellation = null,
    ): self;

    protected function __construct(private readonly PostgresHandle $handle)
    {
    }

    final public function getConfig(): PostgresConfig
    {
        return $this->handle->getConfig();
    }

    final public function getLastUsedAt(): int
    {
        return $this->handle->getLastUsedAt();
    }

    final public function close(): void
    {
        $this->handle->close();
    }

    final public function isClosed(): bool
    {
        return $this->handle->isClosed();
    }

    final public function onClose(\Closure $onClose): void
    {
        $this->handle->onClose($onClose);
    }

    private function awaitPending(): void
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }
    }

    /**
     * Reserves the connection for a transaction.
     */
    private function reserve(): void
    {
        \assert($this->busy === null);
        $this->busy = new DeferredFuture;
    }

    /**
     * Releases the transaction lock.
     */
    private function release(): void
    {
        \assert($this->busy !== null);

        $this->busy->complete();
        $this->busy = null;
    }

    final public function query(string $sql): PostgresResult
    {
        $this->awaitPending();
        return $this->handle->query($sql);
    }

    final public function execute(string $sql, array $params = []): PostgresResult
    {
        $this->awaitPending();
        return $this->handle->execute($sql, $params);
    }

    final public function prepare(string $sql): PostgresStatement
    {
        $this->awaitPending();
        return $this->handle->prepare($sql);
    }

    final public function notify(string $channel, string $payload = ""): PostgresResult
    {
        $this->awaitPending();
        return $this->handle->notify($channel, $payload);
    }

    final public function listen(string $channel): PostgresListener
    {
        $this->awaitPending();
        return $this->handle->listen($channel);
    }

    final public function beginTransaction(): PostgresTransaction
    {
        $this->reserve();

        try {
            $this->handle->query("BEGIN TRANSACTION ISOLATION LEVEL " . $this->transactionIsolation->toSql());
        } catch (\Throwable $exception) {
            $this->release();
            throw $exception;
        }

        return new Internal\PostgresConnectionTransaction(
            $this->handle,
            $this->release(...),
            $this->transactionIsolation,
        );
    }

    final public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->transactionIsolation;
    }

    final public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        $this->transactionIsolation = $isolation;
    }

    final public function quoteLiteral(string $data): string
    {
        return $this->handle->quoteLiteral($data);
    }

    final public function quoteIdentifier(string $name): string
    {
        return $this->handle->quoteIdentifier($name);
    }

    final public function escapeByteA(string $data): string
    {
        return $this->handle->escapeByteA($data);
    }
}
