<?php declare(strict_types=1);

namespace Amp\Sql;

interface SqlTransactionIsolation
{
    /**
     * @return string Human-readable label for the transaction isolation level.
     */
    public function getLabel(): string;

    /**
     * @return string SQL to be inserted as the transaction isolation level.
     */
    public function toSql(): string;
}
