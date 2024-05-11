<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;

/** @internal */
trait PostgresTransactionDelegate
{
    abstract protected function getExecutor(): PostgresExecutor;

    /**
     * @param \Closure():void $release
     */
    protected function createStatement(
        SqlStatement $statement,
        \Closure $release,
        ?\Closure $awaitBusyResource = null,
    ): PostgresStatement {
        \assert($statement instanceof PostgresStatement);
        return new PostgresPooledStatement($statement, $release, $awaitBusyResource);
    }

    /**
     * @param \Closure():void $release
     */
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new PostgresPooledResult($result, $release);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function query(string $sql): PostgresResult
    {
        return parent::query($sql);
    }

    /**
     * Changes return type to this library's Statement type.
     */
    public function prepare(string $sql): PostgresStatement
    {
        return parent::prepare($sql);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function execute(string $sql, array $params = []): PostgresResult
    {
        return parent::execute($sql, $params);
    }

    /**
     * Changes return type to this library's Transaction type.
     */
    public function beginTransaction(): PostgresTransaction
    {
        return parent::beginTransaction();
    }

    /**
     * @param non-empty-string $channel
     */
    public function notify(string $channel, string $payload = ""): PostgresResult
    {
        return $this->getExecutor()->notify($channel, $payload);
    }

    public function quoteLiteral(string $data): string
    {
        return $this->getExecutor()->quoteLiteral($data);
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->getExecutor()->quoteIdentifier($name);
    }

    public function escapeByteA(string $data): string
    {
        return $this->getExecutor()->escapeByteA($data);
    }
}
