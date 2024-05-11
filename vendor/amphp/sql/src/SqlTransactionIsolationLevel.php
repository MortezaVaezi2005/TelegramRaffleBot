<?php declare(strict_types=1);

namespace Amp\Sql;

enum SqlTransactionIsolationLevel implements SqlTransactionIsolation
{
    case Uncommitted;
    case Committed;
    case Repeatable;
    case Serializable;

    public function getLabel(): string
    {
        return match ($this) {
            self::Uncommitted => 'Uncommitted',
            self::Committed => 'Committed',
            self::Repeatable => 'Repeatable',
            self::Serializable => 'Serializable',
        };
    }

    public function toSql(): string
    {
        return match ($this) {
            self::Uncommitted => 'READ UNCOMMITTED',
            self::Committed => 'READ COMMITTED',
            self::Repeatable => 'REPEATABLE READ',
            self::Serializable => 'SERIALIZABLE',
        };
    }
}
