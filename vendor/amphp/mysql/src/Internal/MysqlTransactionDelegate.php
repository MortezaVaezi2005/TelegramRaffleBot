<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;

/** @internal */
trait MysqlTransactionDelegate
{
    protected function createStatement(
        SqlStatement $statement,
        \Closure $release,
        ?\Closure $awaitBusyResource = null,
    ): MysqlStatement {
        \assert($statement instanceof MysqlStatement);
        return new MysqlPooledStatement($statement, $release, $awaitBusyResource);
    }

    protected function createResult(SqlResult $result, \Closure $release): MysqlResult
    {
        \assert($result instanceof MysqlResult);
        return new MysqlPooledResult($result, $release);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function query(string $sql): MysqlResult
    {
        return parent::query($sql);
    }

    /**
     * Changes return type to this library's Statement type.
     */
    public function prepare(string $sql): MysqlStatement
    {
        return parent::prepare($sql);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function execute(string $sql, array $params = []): MysqlResult
    {
        return parent::execute($sql, $params);
    }

    /**
     * Changes return type to this library's Transaction type.
     */
    public function beginTransaction(): MysqlTransaction
    {
        return parent::beginTransaction();
    }
}
