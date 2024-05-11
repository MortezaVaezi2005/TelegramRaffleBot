<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Future;
use Amp\Mysql\MysqlResult;
use Amp\Sql\Common\SqlCommandResult;

/**
 * @internal
 * @psalm-import-type TFieldType from MysqlResult
 * @extends SqlCommandResult<TFieldType, MysqlResult>
 */
final class MysqlCommandResult extends SqlCommandResult implements MysqlResult
{
    private ?int $lastInsertId;

    public function __construct(int $affectedRows, int $lastInsertId)
    {
        /** @var Future<MysqlResult|null> $future Explicit declaration for Psalm. */
        $future = Future::complete();

        parent::__construct($affectedRows, $future);
        $this->lastInsertId = $lastInsertId ?: null; // Convert 0 to null
    }

    /**
     * Changes return type to this library's Result type.
     */
    public function getNextResult(): ?MysqlResult
    {
        return parent::getNextResult();
    }

    /**
     * @return int|null Insert ID of the last auto increment row or null if not applicable to the query.
     */
    public function getLastInsertId(): ?int
    {
        return $this->lastInsertId;
    }

    /**
     * @return null Always returns null as command results do not have a field list.
     */
    public function getColumnDefinitions(): ?array
    {
        return null; // Command results do not have a field list.
    }
}
