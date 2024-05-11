<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Sql\Common\SqlPooledStatement;
use Amp\Sql\SqlResult;

/**
 * @internal
 * @extends SqlPooledStatement<PostgresResult, PostgresStatement>
 */
final class PostgresPooledStatement extends SqlPooledStatement implements PostgresStatement
{
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new PostgresPooledResult($result, $release);
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function execute(array $params = []): PostgresResult
    {
        return parent::execute($params);
    }
}
