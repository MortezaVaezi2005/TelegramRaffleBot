<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\SocketConnector;
use Amp\Socket\SocketException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sql\SqlTransactionIsolationLevel;
use Revolt\EventLoop;

final class SocketMysqlConnection implements MysqlConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private SqlTransactionIsolation $transactionIsolation = SqlTransactionIsolationLevel::Committed;

    private ?DeferredFuture $busy = null;

    /** @var \Closure():void Function used to release connection after a transaction has completed. */
    private readonly \Closure $release;

    public static function connect(
        SocketConnector $connector,
        MysqlConfig $config,
        ?Cancellation $cancellation = null,
    ): self {
        try {
            $socket = $connector->connect($config->getConnectionString(), $config->getConnectContext(), $cancellation);
        } catch (SocketException $exception) {
            throw new SqlException(
                'Connecting to the MySQL server failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $processor = new Internal\ConnectionProcessor($socket, $config);
        $processor->connect($cancellation);
        return new self($processor);
    }

    private function __construct(private readonly Internal\ConnectionProcessor $processor)
    {
        $busy = &$this->busy;
        $this->release = static function () use (&$busy): void {
            $busy?->complete();
            $busy = null;
        };
    }

    public function getConfig(): MysqlConfig
    {
        return $this->processor->getConfig();
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->transactionIsolation;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        $this->transactionIsolation = $isolation;
    }

    /**
     * @return bool False if the connection has been closed.
     */
    public function isClosed(): bool
    {
        return $this->processor->isClosed();
    }

    /**
     * @return int Timestamp of the last time this connection was used.
     */
    public function getLastUsedAt(): int
    {
        return $this->processor->getLastUsedAt();
    }

    public function useCharacterSet(string $charset, string $collate): void
    {
        $this->processor->useCharacterSet($charset, $collate)->await();
    }

    public function close(): void
    {
        // Send close command if connection is not already in a closed or closing state
        if (!$this->processor->isClosed()) {
            $this->processor->sendClose()->await();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->processor->onClose($onClose);
    }

    public function useDatabase(string $database): void
    {
        $this->processor->useDatabase($database)->await();
    }

    public function query(string $sql): MysqlResult
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->query($sql)->await();
    }

    public function beginTransaction(): MysqlTransaction
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        $this->busy = $deferred = new DeferredFuture();

        $sql = \sprintf(
            "SET SESSION TRANSACTION ISOLATION LEVEL %s; START TRANSACTION",
            $this->transactionIsolation->toSql(),
        );

        try {
            $this->processor->query($sql)->await();
        } catch (\Throwable $exception) {
            $this->busy = null;
            $deferred->complete();
            throw $exception;
        }

        $executor = new Internal\MysqlNestableExecutor($this->processor);
        return new Internal\MysqlConnectionTransaction($executor, $this->release, $this->transactionIsolation);
    }

    public function ping(): void
    {
        $this->processor->ping()->await();
    }

    public function prepare(string $sql): MysqlStatement
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        return $this->processor->prepare($sql)->await();
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        $statement = $this->prepare($sql);
        return $statement->execute($params);
    }

    public function __destruct()
    {
        $processor = $this->processor;
        EventLoop::queue(static fn () => $processor->unreference());
    }
}
