<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\Common\SqlConnectionTransaction;
use Amp\Sql\Common\SqlNestableTransactionExecutor;
use Amp\Sql\SqlTransaction;

/**
 * @internal
 * @extends SqlConnectionTransaction<MysqlResult, MysqlStatement, MysqlTransaction, MysqlNestableExecutor>
 */
final class MysqlConnectionTransaction extends SqlConnectionTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    protected function createNestedTransaction(
        SqlTransaction $transaction,
        SqlNestableTransactionExecutor $executor,
        string $identifier,
        \Closure $release,
    ): MysqlTransaction {
        \assert($transaction instanceof MysqlTransaction);
        return new MysqlNestedTransaction($transaction, $executor, $identifier, $release);
    }
}
