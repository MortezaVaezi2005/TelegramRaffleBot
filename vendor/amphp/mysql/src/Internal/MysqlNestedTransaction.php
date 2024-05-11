<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\Common\SqlNestableTransactionExecutor;
use Amp\Sql\Common\SqlNestedTransaction;
use Amp\Sql\SqlTransaction;

/**
 * @internal
 * @extends SqlNestedTransaction<MysqlResult, MysqlStatement, MysqlTransaction, MysqlNestableExecutor>
 */
final class MysqlNestedTransaction extends SqlNestedTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    /**
     * @param non-empty-string $identifier
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly MysqlTransaction $transaction,
        MysqlNestableExecutor $executor,
        string $identifier,
        \Closure $release,
    ) {
        parent::__construct($transaction, $executor, $identifier, $release);
    }

    protected function getTransaction(): MysqlTransaction
    {
        return $this->transaction;
    }

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): MysqlTransaction {
        return new self($transaction, $executor, $identifier, $release);
    }
}
