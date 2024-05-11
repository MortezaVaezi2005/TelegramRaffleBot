<?php declare(strict_types=1);

namespace Amp\Redis\Protocol;

/**
 * @psalm-type RedisValueType = int|string|list<mixed>|null
 */
interface RedisResponse
{
    /**
     * @return RedisValueType
     */
    public function unwrap(): int|string|array|null;
}
