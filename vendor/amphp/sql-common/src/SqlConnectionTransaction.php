<?php declare(strict_types=1);

namespace Amp\Sql\Common;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sql\SqlException;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Amp\Sql\SqlTransaction;
use Amp\Sql\SqlTransactionError;
use Amp\Sql\SqlTransactionIsolation;
use Revolt\EventLoop;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 * @template TNestedExecutor of SqlNestableTransactionExecutor<TResult, TStatement>
 *
 * @implements SqlTransaction<TResult, TStatement, TTransaction>
 */
abstract class SqlConnectionTransaction implements SqlTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \Closure():void */
    private readonly \Closure $release;

    private int $refCount = 1;

    private bool $active = true;

    private readonly DeferredFuture $onCommit;
    private readonly DeferredFuture $onRollback;
    private readonly DeferredFuture $onClose;

    private ?DeferredFuture $busy = null;

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
     * Creates a Statement of the appropriate type using the Statement object returned by the Transaction object and
     * the given release callable.
     *
     * @param TStatement $statement
     * @param \Closure():void $release
     * @param \Closure():void $awaitBusyResource
     *
     * @return TStatement
     */
    abstract protected function createStatement(
        SqlStatement $statement,
        \Closure $release,
        \Closure $awaitBusyResource,
    ): SqlStatement;

    /**
     * @param TTransaction $transaction
     * @param TNestedExecutor $executor
     * @param non-empty-string $identifier
     * @param \Closure():void $release
     *
     * @return TTransaction
     */
    abstract protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): SqlTransaction;

    /**
     * @param TNestedExecutor $executor
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly SqlNestableTransactionExecutor $executor,
        \Closure $release,
        private readonly SqlTransactionIsolation $isolation,
    ) {
        $busy = &$this->busy;
        $refCount = &$this->refCount;
        $this->release = static function () use (&$busy, &$refCount, $release): void {
            $busy?->complete();
            $busy = null;

            if (--$refCount === 0) {
                $release();
            }
        };

        $this->onCommit = new DeferredFuture();
        $this->onRollback = new DeferredFuture();
        $this->onClose = new DeferredFuture();

        $this->onClose($this->release);
    }

    public function __destruct()
    {
        if (!$this->active) {
            return;
        }

        if ($this->executor->isClosed()) {
            $this->onRollback->complete();
            $this->onClose->complete();
        }

        $busy = &$this->busy;
        $executor = $this->executor;
        $onRollback = $this->onRollback;
        $onClose = $this->onClose;
        EventLoop::queue(static function () use (&$busy, $executor, $onRollback, $onClose): void {
            try {
                while ($busy) {
                    $busy->getFuture()->await();
                }

                if (!$executor->isClosed()) {
                    $executor->rollback();
                }
            } catch (SqlException) {
                // Ignore failure if connection closes during query.
            } finally {
                $onRollback->complete();
                $onClose->complete();
            }
        });
    }

    public function getLastUsedAt(): int
    {
        return $this->executor->getLastUsedAt();
    }

    public function getSavepointIdentifier(): ?string
    {
        return null;
    }

    /**
     * Closes and rolls back all changes in the transaction.
     */
    public function close(): void
    {
        if (!$this->active) {
            return;
        }

        $this->rollback(); // Invokes $this->release callback.
    }

    public function isClosed(): bool
    {
        return $this->onClose->isComplete();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    /**
     * @return bool True if the transaction is active, false if it has been committed or rolled back.
     */
    public function isActive(): bool
    {
        return $this->active && !$this->executor->isClosed();
    }

    public function getIsolation(): SqlTransactionIsolation
    {
        return $this->isolation;
    }

    /**
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function query(string $sql): SqlResult
    {
        $this->awaitPendingNestedTransaction();

        ++$this->refCount;
        try {
            $result = $this->executor->query($sql);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return $this->createResult($result, $this->release);
    }

    /**
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     *
     * @psalm-suppress InvalidReturnStatement, InvalidReturnType
     */
    public function prepare(string $sql): SqlStatement
    {
        $this->awaitPendingNestedTransaction();

        ++$this->refCount;
        try {
            $statement = $this->executor->prepare($sql);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        $busy = &$this->busy;
        return $this->createStatement($statement, $this->release, static function () use (&$busy): void {
            while ($busy) {
                $busy->getFuture()->await();
            }
        });
    }

    /**
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->awaitPendingNestedTransaction();

        ++$this->refCount;
        try {
            $result = $this->executor->execute($sql, $params);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return $this->createResult($result, $this->release);
    }

    public function beginTransaction(): SqlTransaction
    {
        $this->awaitPendingNestedTransaction();

        ++$this->refCount;
        $this->busy = new DeferredFuture();
        try {
            $identifier = \bin2hex(\random_bytes(8));
            $this->executor->createSavepoint($identifier);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        /** @psalm-suppress InvalidArgument Recursive templates prevent satisfying this call. */
        return $this->createNestedTransaction($this, $this->executor, $identifier, $this->release);
    }

    /**
     * Commits the transaction and makes it inactive.
     *
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function commit(): void
    {
        $this->active = false;
        $this->awaitPendingNestedTransaction();

        try {
            $this->executor->commit();
        } finally {
            $this->onCommit->complete();
            $this->onClose->complete();
        }
    }

    /**
     * Rolls back the transaction and makes it inactive.
     *
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function rollback(): void
    {
        $this->active = false;
        $this->awaitPendingNestedTransaction();

        try {
            $this->executor->rollback();
        } finally {
            $this->onRollback->complete();
            $this->onClose->complete();
        }
    }

    public function onCommit(\Closure $onCommit): void
    {
        $this->onCommit->getFuture()->finally($onCommit);
    }

    public function onRollback(\Closure $onRollback): void
    {
        $this->onRollback->getFuture()->finally($onRollback);
    }

    private function awaitPendingNestedTransaction(): void
    {
        while ($this->busy) {
            $this->busy->getFuture()->await();
        }

        if ($this->isClosed()) {
            throw new SqlTransactionError("The transaction has been committed or rolled back");
        }
    }
}
