<?php declare(strict_types=1);

namespace Amp\Sql\Common;

use Amp\Sql\SqlExecutor;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 *
 * @extends SqlExecutor<TResult, TStatement>
 */
interface SqlNestableTransactionExecutor extends SqlExecutor
{
    /**
     * Commits the current transaction.
     */
    public function commit(): void;

    /**
     * Rolls back the current transaction.
     */
    public function rollback(): void;

    /**
     * Creates a savepoint with the given identifier.
     *
     * @param non-empty-string $identifier Savepoint identifier.
     */
    public function createSavepoint(string $identifier): void;

    /**
     * Rolls back to the savepoint with the given identifier.
     *
     * @param non-empty-string $identifier Savepoint identifier.
     */
    public function rollbackTo(string $identifier): void;

    /**
     * Releases the savepoint with the given identifier.
     *
     * @param non-empty-string $identifier Savepoint identifier.
     */
    public function releaseSavepoint(string $identifier): void;
}
