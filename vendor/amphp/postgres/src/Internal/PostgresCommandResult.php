<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresResult;
use Amp\Sql\Common\SqlCommandResult;

/**
 * @internal
 * @psalm-import-type TFieldType from PostgresResult
 * @extends SqlCommandResult<TFieldType, PostgresResult>
 */
final class PostgresCommandResult extends SqlCommandResult implements PostgresResult
{
    /**
     * Changes return type to this library's Result type.
     */
    public function getNextResult(): ?PostgresResult
    {
        return parent::getNextResult();
    }
}
