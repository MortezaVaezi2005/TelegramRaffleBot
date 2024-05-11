<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlConnection;

/**
 * @extends SqlConnection<MysqlConfig, MysqlResult, MysqlStatement, MysqlTransaction>
 */
interface MysqlConnection extends MysqlLink, SqlConnection
{
    /**
     * @return MysqlConfig Config object specific to this library.
     */
    public function getConfig(): MysqlConfig;
}
