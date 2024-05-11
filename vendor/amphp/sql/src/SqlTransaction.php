<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 *
 * @extends SqlLink<TResult, TStatement, TTransaction>
 */
interface SqlTransaction extends SqlLink
{
    public function getIsolation(): SqlTransactionIsolation;

    /**
     * @return bool True if the transaction is active, false if it has been committed or rolled back.
     */
    public function isActive(): bool;

    /**
     * @return string|null Nested transaction identifier or null if a top-level transaction.
     */
    public function getSavepointIdentifier(): ?string;

    /**
     * Commits the transaction and makes it inactive.
     *
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function commit(): void;

    /**
     * Rolls back the transaction and makes it inactive.
     *
     * @throws SqlTransactionError If the transaction has been committed or rolled back.
     */
    public function rollback(): void;

    /**
     * Attaches a callback which is invoked when the entire transaction is committed. If this transaction
     * is a nested transaction, the callback will not be invoked until the top-level transaction is committed.
     *
     * @param \Closure():void $onCommit
     */
    public function onCommit(\Closure $onCommit): void;

    /**
     * Attaches a callback which is invoked when the transaction is rolled back. If in a nested transaction, the
     * callbacks may be invoked when rolling back to a savepoint or if the entire transaction is rolled back,
     * regardless of if the savepoint was released prior.
     *
     * @param \Closure():void $onRollback
     */
    public function onRollback(\Closure $onRollback): void;
}
