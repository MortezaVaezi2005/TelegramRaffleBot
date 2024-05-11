<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlExecutor;

/**
 * @extends SqlExecutor<MysqlResult, MysqlStatement>
 */
interface MysqlExecutor extends SqlExecutor
{
    /**
     * @return MysqlResult Result object specific to this library.
     */
    public function query(string $sql): MysqlResult;

    /**
     * @return MysqlStatement Statement object specific to this library.
     */
    public function prepare(string $sql): MysqlStatement;

    /**
     * @return MysqlResult Result object specific to this library.
     */
    public function execute(string $sql, array $params = []): MysqlResult;
}
