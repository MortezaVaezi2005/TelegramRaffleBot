<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlLink;

/**
 * @extends SqlLink<MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlLink extends MysqlExecutor, SqlLink
{
    /**
     * @return MysqlTransaction Transaction object specific to this library.
     */
    public function beginTransaction(): MysqlTransaction;
}
