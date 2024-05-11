<?php declare(strict_types=1);

namespace Amp\Sql\Common;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Amp\Sql\SqlTransaction;
use Amp\Sql\SqlTransactionIsolation;
use Revolt\EventLoop;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement
 * @template TTransaction of SqlTransaction
 *
 * @implements SqlTransaction<TResult, TStatement, TTransaction>
 */
abstract class SqlPooledTransaction implements SqlTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \Closure():void */
    private readonly \Closure $release;

    private int $refCount = 1;

    /**
     * Creates a Statement of the appropriate type using the Statement object returned by the Transaction object and
     * the given release callable.
     *
     * @param TStatement $statement
     * @param \Closure():void $release
     *
     * @return TStatement
     */
    abstract protected function createStatement(SqlStatement $statement, \Closure $release): SqlStatement;

    /**
     * Creates a Result of the appropriate type using the Result object returned by the Link object and the
     * given release callable.
     *
     * @param TResult $result
     * @param \Closure():void $release
     *
     * @return TResult
     */
    abstract protected function createResult(SqlResult $result, \Closure $release): SqlResult;

    /**
     * @param TTransaction $transaction
     * @param \Closure():void $release
     *
     * @return TTransaction
     */
    abstract protected function createTransaction(SqlTransaction $transaction, \Closure $release): SqlTransaction;

    /**
     * @param TTransaction $transaction Transaction object created by pooled connection.
     * @param \Closure():void $release Callable to be invoked when the transaction completes or is destroyed.
     */
    public function __construct(private readonly SqlTransaction $transaction, \Closure $release)
    {
        $refCount = &$this->refCount;
        $this->release = static function () use (&$refCount, $release): void {
            if (--$refCount === 0) {
                $release();
            }
        };

        $this->transaction->onClose($this->release);

        if (!$this->transaction->isActive()) {
            $this->close();
        }
    }

    public function query(string $sql): SqlResult
    {
        ++$this->refCount;

        try {
            $result = $this->transaction->query($sql);
            return $this->createResult($result, $this->release);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }
    }

    public function prepare(string $sql): SqlStatement
    {
        ++$this->refCount;

        try {
            $statement = $this->transaction->prepare($sql);
            return $this->createStatement($statement, $this->release);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        ++$this->refCount;

        try {
            $result = $this->transaction->execute($sql, $params);
            return $this->createResult($result, $this->release);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }
    }

    public function beginTransaction(): SqlTransaction
    {
        ++$this->refCount;

        try {
            $transaction = $this->transaction->beginTransaction();
            return $this->createTransaction($transaction, $this->release);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }
    }

    public function isClosed(): bool
    {
        return $this->transaction->isClosed();
    }

    /**
     * Rolls back the transaction if it has not been committed.
     */
    public function close(): void
    {
        $this->transaction->close();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->transaction->onClose($onClose);
    }

    public function isActive(): bool
    {
        return $this->transaction->isActive();
    }

    public function commit(): void
    {
        $this->transaction->commit();
    }

    public function rollback(): void
    {
        $this->transaction->rollback();
    }

    public function onCommit(\Closure $onCommit): void
    {
        $this->transaction->onCommit($onCommit);
    }

    public function onRollback(\Closure $onRollback): void
    {
        $this->transaction->onRollback($onRollback);
    }

    public function getSavepointIdentifier(): ?string
    {
        return $this->transaction->getSavepointIdentifier();
    }

    public function getIsolation(): SqlTransactionIsolation
    {
        return $this->transaction->getIsolation();
    }

    public function getLastUsedAt(): int
    {
        return $this->transaction->getLastUsedAt();
    }
}
