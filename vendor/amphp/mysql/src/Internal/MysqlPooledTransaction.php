<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\Common\SqlPooledTransaction;
use Amp\Sql\SqlTransaction;

/**
 * @internal
 * @extends SqlPooledTransaction<MysqlResult, MysqlStatement, MysqlTransaction>
 */
final class MysqlPooledTransaction extends SqlPooledTransaction implements MysqlTransaction
{
    use MysqlTransactionDelegate;

    /**
     * @param \Closure():void $release
     */
    public function __construct(private readonly MysqlTransaction $transaction, \Closure $release)
    {
        parent::__construct($transaction, $release);
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): MysqlTransaction
    {
        \assert($transaction instanceof MysqlTransaction);
        return new MysqlPooledTransaction($transaction, $release);
    }
}
