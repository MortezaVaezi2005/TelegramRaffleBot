<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlTransaction;

/**
 * @extends SqlTransaction<MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlTransaction extends MysqlLink, SqlTransaction
{
}
