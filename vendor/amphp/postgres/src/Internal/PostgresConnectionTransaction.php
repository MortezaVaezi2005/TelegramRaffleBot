<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\Common\SqlConnectionTransaction;
use Amp\Sql\Common\SqlNestableTransactionExecutor;
use Amp\Sql\SqlTransaction;
use Amp\Sql\SqlTransactionIsolation;

/**
 * @internal
 * @extends SqlConnectionTransaction<PostgresResult, PostgresStatement, PostgresTransaction, PostgresHandle>
 */
final class PostgresConnectionTransaction extends SqlConnectionTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    public function __construct(
        private readonly PostgresHandle $handle,
        \Closure $release,
        SqlTransactionIsolation $isolation
    ) {
        parent::__construct($handle, $release, $isolation);
    }

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): PostgresTransaction {
        \assert($executor instanceof PostgresHandle);
        return new PostgresNestedTransaction($this, $executor, $identifier, $release);
    }

    protected function getExecutor(): PostgresExecutor
    {
        return $this->handle;
    }
}
