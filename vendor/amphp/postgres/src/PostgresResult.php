<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlResult;

/**
 * Recursive template types currently not supported, list<mixed> should be list<TFieldType>.
 * @psalm-type TFieldType = list<mixed>|scalar|null
 * @psalm-type TRowType = array<string, TFieldType>
 * @extends SqlResult<TFieldType>
 */
interface PostgresResult extends SqlResult
{
    /**
     * Changes return type to this library's Result type.
     */
    public function getNextResult(): ?self;
}
