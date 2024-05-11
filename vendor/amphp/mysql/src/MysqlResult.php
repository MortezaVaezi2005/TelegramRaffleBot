<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlResult;

/**
 * @psalm-type TFieldType = int|float|string|null
 * @psalm-type TRowType = array<string, TFieldType>
 *
 * @extends SqlResult<TFieldType>
 */
interface MysqlResult extends SqlResult
{
    /**
     * Changes return type to this library's Result type.
     */
    public function getNextResult(): ?self;

    /**
     * @return int|null Insert ID of the last auto increment row if applicable to the result or null if no ID
     *                  is available.
     */
    public function getLastInsertId(): ?int;

    /**
     * @return list<MysqlColumnDefinition>|null
     */
    public function getColumnDefinitions(): ?array;
}
