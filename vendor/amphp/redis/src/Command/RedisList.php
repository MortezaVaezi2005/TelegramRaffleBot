<?php declare(strict_types=1);
/** @noinspection DuplicatedCode */

namespace Amp\Redis\Command;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Command\Option\SortOptions;
use Amp\Redis\RedisClient;

final class RedisList
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly RedisClient $client,
        private readonly string $key,
    ) {
    }

    /**
     * @link https://redis.io/commands/lindex
     */
    public function get(string $index): string
    {
        return $this->client->execute('lindex', $this->key, $index);
    }

    /**
     * @link https://redis.io/commands/linsert
     */
    public function insertBefore(string $pivot, string $value): int
    {
        return $this->client->execute('linsert', $this->key, 'BEFORE', $pivot, $value);
    }

    /**
     * @link https://redis.io/commands/linsert
     */
    public function insertAfter(string $pivot, string $value): int
    {
        return $this->client->execute('linsert', $this->key, 'AFTER', $pivot, $value);
    }

    /**
     *
     * @link https://redis.io/commands/llen
     */
    public function getSize(): int
    {
        return $this->client->execute('llen', $this->key);
    }

    /**
     * @link https://redis.io/commands/lpush
     */
    public function pushHead(string $value, string ...$values): int
    {
        return $this->client->execute('lpush', $this->key, $value, ...$values);
    }

    /**
     * @link https://redis.io/commands/lpushx
     */
    public function pushHeadIfExists(string $value, string ...$values): int
    {
        return $this->client->execute('lpushx', $this->key, $value, ...$values);
    }

    /**
     * @link https://redis.io/commands/rpush
     */
    public function pushTail(string $value, string ...$values): int
    {
        return $this->client->execute('rpush', $this->key, $value, ...$values);
    }

    /**
     * @link https://redis.io/commands/rpushx
     */
    public function pushTailIfExists(string $value, string ...$values): int
    {
        return $this->client->execute('rpushx', $this->key, $value, ...$values);
    }

    /**
     *
     * @link https://redis.io/commands/lpop
     */
    public function popHead(): ?string
    {
        return $this->client->execute('lpop', $this->key);
    }

    /**
     * @link https://redis.io/commands/blpop
     */
    public function popHeadBlocking(int $timeout = 0): ?string
    {
        $response = $this->client->execute('blpop', $this->key, $timeout);
        return $response[1] ?? null;
    }

    /**
     * @link https://redis.io/commands/rpop
     */
    public function popTail(): ?string
    {
        return $this->client->execute('rpop', $this->key);
    }

    /**
     * @link https://redis.io/commands/brpop
     */
    public function popTailBlocking(int $timeout = 0): ?string
    {
        $response = $this->client->execute('brpop', $this->key, $timeout);
        return $response[1] ?? null;
    }

    /**
     * @link https://redis.io/commands/rpoplpush
     */
    public function popTailPushHead(string $destination): string
    {
        return $this->client->execute('rpoplpush', $this->key, $destination);
    }

    /**
     * @link https://redis.io/commands/brpoplpush
     */
    public function popTailPushHeadBlocking(string $destination, int $timeout = 0): ?string
    {
        return $this->client->execute('brpoplpush', $this->key, $destination, $timeout);
    }

    /**
     * @link https://redis.io/commands/lrange
     */
    public function getRange(int $start = 0, int $end = -1): array
    {
        return $this->client->execute('lrange', $this->key, $start, $end);
    }

    /**
     * @link https://redis.io/commands/lrem
     */
    public function remove(string $value, int $count = 0): int
    {
        return $this->client->execute('lrem', $this->key, $count, $value);
    }

    /**
     * @link https://redis.io/commands/lset
     */
    public function set(int $index, string $value): void
    {
        $this->client->execute('lset', $this->key, $index, $value);
    }

    /**
     * @link https://redis.io/commands/ltrim
     */
    public function trim(int $start = 0, int $stop = -1): void
    {
        $this->client->execute('ltrim', $this->key, $start, $stop);
    }

    /**
     * @link https://redis.io/commands/sort
     */
    public function sort(?SortOptions $options = null): array
    {
        return $this->client->execute('SORT', $this->key, ...($options ?? new SortOptions)->toQuery());
    }
}
