<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\Common\SqlNestableTransactionExecutor;
use Amp\Sql\Common\SqlNestedTransaction;
use Amp\Sql\SqlTransaction;

/**
 * @internal
 * @extends SqlNestedTransaction<PostgresResult, PostgresStatement, PostgresTransaction, PostgresHandle>
 */
final class PostgresNestedTransaction extends SqlNestedTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    /**
     * @param non-empty-string $identifier
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly PostgresTransaction $transaction,
        PostgresHandle $handle,
        string $identifier,
        \Closure $release,
    ) {
        parent::__construct($transaction, $handle, $identifier, $release);
    }

    protected function getExecutor(): PostgresExecutor
    {
        return $this->transaction;
    }

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): PostgresTransaction {
        return new self($transaction, $executor, $identifier, $release);
    }

    public function prepare(string $sql): PostgresStatement
    {
        $statement = parent::prepare($sql);

        // Defer statement deallocation until parent is committed or rolled back.
        $this->transaction->onClose(static fn () => $statement);

        return $statement;
    }
}
