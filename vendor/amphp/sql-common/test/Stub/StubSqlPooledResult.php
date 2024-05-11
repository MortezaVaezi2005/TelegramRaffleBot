<?php declare(strict_types=1);

namespace Amp\Sql\Common\Test\Stub;

use Amp\Sql\Common\SqlPooledResult;
use Amp\Sql\SqlResult;

final class StubSqlPooledResult extends SqlPooledResult
{
    protected static function newInstanceFrom(SqlResult $result, \Closure $release): self
    {
        return new self($result, $release);
    }
}
