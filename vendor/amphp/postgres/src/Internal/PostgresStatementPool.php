<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\Common\SqlStatementPool as SqlStatementPool;
use Amp\Sql\SqlResult as SqlResult;

/**
 * @internal
 * @extends SqlStatementPool<PostgresConfig, PostgresResult, PostgresStatement, PostgresTransaction>
 */
final class PostgresStatementPool extends SqlStatementPool implements PostgresStatement
{
    protected function createResult(SqlResult $result, \Closure $release): PostgresResult
    {
        \assert($result instanceof PostgresResult);
        return new PostgresPooledResult($result, $release);
    }

    public function execute(array $params = []): PostgresResult
    {
        return parent::execute($params);
    }
}
