<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\Common\SqlPooledTransaction;
use Amp\Sql\SqlTransaction;

/**
 * @internal
 * @extends SqlPooledTransaction<PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresPooledTransaction extends SqlPooledTransaction implements PostgresTransaction
{
    use PostgresTransactionDelegate;

    /**
     * @param \Closure():void $release
     */
    public function __construct(private readonly PostgresTransaction $transaction, \Closure $release)
    {
        parent::__construct($transaction, $release);
    }

    protected function getExecutor(): PostgresExecutor
    {
        return $this->transaction;
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): PostgresTransaction
    {
        \assert($transaction instanceof PostgresTransaction);
        return new PostgresPooledTransaction($transaction, $release);
    }
}
