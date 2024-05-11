<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class MysqlColumnDefinition
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param non-empty-string $table
     * @param non-empty-string $name
     * @param int<0, max> $length
     * @param int<0, max> $flags
     * @param int<0, max> $decimals
     * @param non-empty-string|null $originalTable
     * @param non-empty-string|null $originalName
     * @param int<0, max>|null $charset
     * @param non-empty-string|null $catalog
     * @param non-empty-string|null $schema
     */
    public function __construct(
        private readonly string $table,
        private readonly string $name,
        private readonly int $length,
        private readonly MysqlDataType $type,
        private readonly int $flags,
        private readonly int $decimals,
        private readonly string $defaults = '',
        private readonly ?string $originalTable = null,
        private readonly ?string $originalName = null,
        private readonly ?int $charset = null,
        private readonly ?string $catalog = null,
        private readonly ?string $schema = null,
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int<0, max>
     */
    public function getLength(): int
    {
        return $this->length;
    }

    public function getType(): MysqlDataType
    {
        return $this->type;
    }

    /**
     * @return int<0, max>
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @return int<0, max>
     */
    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function getDefaults(): string
    {
        return $this->defaults;
    }

    /**
     * @return non-empty-string|null
     */
    public function getOriginalTable(): ?string
    {
        return $this->originalTable;
    }

    /**
     * @return non-empty-string|null
     */
    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    /**
     * @return int<0, max>|null
     */
    public function getCharset(): ?int
    {
        return $this->charset;
    }

    /**
     * @return non-empty-string|null
     */
    public function getCatalog(): ?string
    {
        return $this->catalog;
    }

    /**
     * @return non-empty-string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }
}
