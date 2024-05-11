<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Sql\Common\SqlPooledStatement;
use Amp\Sql\SqlResult;

/**
 * @internal
 * @extends SqlPooledStatement<MysqlResult, MysqlStatement>
 */
final class MysqlPooledStatement extends SqlPooledStatement implements MysqlStatement
{
    /**
     * @param \Closure():void $release
     * @param (\Closure():void)|null $awaitBusyResource
     */
    public function __construct(
        private readonly MysqlStatement $statement,
        \Closure $release,
        ?\Closure $awaitBusyResource = null,
    ) {
        parent::__construct($statement, $release, $awaitBusyResource);
    }

    protected function createResult(SqlResult $result, \Closure $release): MysqlResult
    {
        \assert($result instanceof MysqlResult);
        return new MysqlPooledResult($result, $release);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function execute(array $params = []): MysqlResult
    {
        return parent::execute($params);
    }

    public function bind(int|string $paramId, string $data): void
    {
        $this->statement->bind($paramId, $data);
    }

    public function getColumnDefinitions(): array
    {
        return $this->statement->getColumnDefinitions();
    }

    public function getParameterDefinitions(): array
    {
        return $this->statement->getParameterDefinitions();
    }

    public function reset(): void
    {
        $this->statement->reset();
    }
}
