<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlStatement;

/**
 * @extends SqlStatement<MysqlResult>
 */
interface MysqlStatement extends SqlStatement
{
    public function execute(array $params = []): MysqlResult;

    /**
     * @param int|string $paramId Parameter ID or name.
     * @param string $data Data to bind to parameter.
     *
     * @throws \Error If $paramId does not exist.
     */
    public function bind(int|string $paramId, string $data): void;

    /**
     * @return list<MysqlColumnDefinition>
     */
    public function getColumnDefinitions(): array;

    /**
     * @return list<MysqlColumnDefinition>
     */
    public function getParameterDefinitions(): array;

    /**
     * Reset statement to state just after preparing.
     */
    public function reset(): void;
}
