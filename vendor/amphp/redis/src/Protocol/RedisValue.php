<?php declare(strict_types=1);

namespace Amp\Redis\Protocol;

/**
 * @psalm-import-type RedisValueType from RedisResponse
 */
final class RedisValue implements RedisResponse
{
    /**
     * @param RedisValueType $value
     */
    public function __construct(
        private readonly int|string|array|null $value,
    ) {
    }

    public function unwrap(): int|string|array|null
    {
        return $this->value;
    }
}
