<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresResult;
use Amp\Sql\Common\SqlPooledResult;
use Amp\Sql\SqlResult;

/**
 * @internal
 * @psalm-import-type TFieldType from PostgresResult
 * @extends SqlPooledResult<TFieldType, PostgresResult>
 */
final class PostgresPooledResult extends SqlPooledResult implements PostgresResult
{
    protected static function newInstanceFrom(SqlResult $result, \Closure $release): self
    {
        \assert($result instanceof PostgresResult);
        return new self($result, $release);
    }

    public function getNextResult(): ?PostgresResult
    {
        return parent::getNextResult();
    }
}
