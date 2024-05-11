<?php declare(strict_types=1);
/** @noinspection DuplicatedCode */

namespace Amp\Redis\Command;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Command\Option\SortOptions;
use Amp\Redis\RedisClient;

final class RedisSet
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly RedisClient $client,
        private readonly string $key,
    ) {
    }

    public function add(string $member, string ...$members): int
    {
        return $this->client->execute('sadd', $this->key, $member, ...$members);
    }

    public function getSize(): int
    {
        return $this->client->execute('scard', $this->key);
    }

    public function diff(string ...$keys): array
    {
        return $this->client->execute('sdiff', $this->key, ...$keys);
    }

    public function storeDiff(string $key, string ...$keys): int
    {
        return $this->client->execute('sdiffstore', $this->key, $key, ...$keys);
    }

    public function intersect(string ...$keys): array
    {
        return $this->client->execute('sinter', $this->key, ...$keys);
    }

    public function storeIntersection(string $key, string ...$keys): int
    {
        return $this->client->execute('sinterstore', $this->key, $key, ...$keys);
    }

    public function contains(string $member): bool
    {
        return (bool) $this->client->execute('sismember', $this->key, $member);
    }

    public function getAll(): array
    {
        return $this->client->execute('smembers', $this->key);
    }

    public function move(string $member, string $destination): bool
    {
        return (bool) $this->client->execute('smove', $this->key, $destination, $member);
    }

    public function popRandomMember(): string
    {
        return $this->client->execute('spop', $this->key);
    }

    public function getRandomMember(): ?string
    {
        return $this->client->execute('srandmember', $this->key);
    }

    /**
     *
     * @return string[]
     */
    public function getRandomMembers(int $count): array
    {
        return $this->client->execute('srandmember', $this->key, $count);
    }

    public function remove(string $member, string ...$members): int
    {
        return $this->client->execute('srem', $this->key, $member, ...$members);
    }

    public function union(string ...$keys): array
    {
        return $this->client->execute('sunion', $this->key, ...$keys);
    }

    public function storeUnion(string $key, string ...$keys): int
    {
        return $this->client->execute('sunionstore', $this->key, $key, ...$keys);
    }

    /**
     * @return \Traversable<string>
     */
    public function scan(?string $pattern = null, ?int $count = null): \Traversable
    {
        $cursor = 0;

        do {
            $query = [$this->key, $cursor];

            if ($pattern !== null) {
                $query[] = 'MATCH';
                $query[] = $pattern;
            }

            if ($count !== null) {
                $query[] = 'COUNT';
                $query[] = $count;
            }

            [$cursor, $keys] = $this->client->execute('SSCAN', ...$query);

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * @param SortOptions $options
     *
     * @link https://redis.io/commands/sort
     */
    public function sort(?SortOptions $options = null): array
    {
        return $this->client->execute('SORT', $this->key, ...($options ?? new SortOptions)->toQuery());
    }
}
