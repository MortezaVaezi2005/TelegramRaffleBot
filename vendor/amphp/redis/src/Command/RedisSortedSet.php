<?php declare(strict_types=1);

namespace Amp\Redis\Command;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Command\Boundary\LexBoundary;
use Amp\Redis\Command\Boundary\ScoreBoundary;
use Amp\Redis\Command\Option\RangeOptions;
use Amp\Redis\Command\Option\SortOptions;
use Amp\Redis\RedisClient;
use function Amp\Redis\Internal\toMap;

final class RedisSortedSet
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly RedisClient $client;
    private readonly string $key;

    public function __construct(RedisClient $client, string $key)
    {
        $this->client = $client;
        $this->key = $key;
    }

    /**
     * @param array<string, int|float> $data
     *
     * @return int Number of items added.
     */
    public function add(array $data): int
    {
        $payload = [$this->key];

        foreach ($data as $member => $score) {
            $payload[] = $score;
            $payload[] = $member;
        }

        return $this->client->execute('zadd', ...$payload);
    }

    /**
     * @return list<string>
     */
    public function getRange(int $start, int $end, ?RangeOptions $options = null): array
    {
        $options ??= new RangeOptions();
        return $this->client->execute('zrange', $this->key, $start, $end, ...$options->toQuery());
    }

    /**
     * @return array<string, float>
     */
    public function getRangeWithScores(int $start, int $end, ?RangeOptions $options = null): array
    {
        $options ??= new RangeOptions();
        return \array_map(\floatval(...), toMap($this->client->execute(
            'zrange',
            $this->key,
            $start,
            $end,
            'WITHSCORES',
            ...$options->toQuery(),
        )));
    }

    /**
     * @return list<string>
     */
    public function getRangeByScore(ScoreBoundary $min, ScoreBoundary $max, ?RangeOptions $options = null): array
    {
        $options ??= new RangeOptions();
        return $this->client->execute(
            'zrange',
            $this->key,
            $min->toQuery(),
            $max->toQuery(),
            'BYSCORE',
            ...$options->toQuery(),
        );
    }

    /**
     * @return array<string, float>
     */
    public function getRangeByScoreWithScores(ScoreBoundary $min, ScoreBoundary $max, ?RangeOptions $options = null): array
    {
        $options ??= new RangeOptions();
        return \array_map(\floatval(...), toMap($this->client->execute(
            'zrange',
            $this->key,
            $min->toQuery(),
            $max->toQuery(),
            'BYSCORE',
            'WITHSCORES',
            ...$options->toQuery(),
        )));
    }

    public function getLexicographicRange(LexBoundary $start, LexBoundary $end, ?RangeOptions $options = null): array
    {
        $options ??= new RangeOptions();
        return $this->client->execute(
            'zrange',
            $this->key,
            $start->toQuery(),
            $end->toQuery(),
            'BYLEX',
            ...$options->toQuery(),
        );
    }

    public function getSize(): int
    {
        return $this->client->execute('zcard', $this->key);
    }

    public function count(int $min, int $max): int
    {
        return $this->client->execute('zcount', $this->key, $min, $max);
    }

    public function increment(string $member, float $increment = 1): float
    {
        return (float) $this->client->execute('zincrby', $this->key, $increment, $member);
    }

    /**
     * @param string[] $keys
     */
    public function storeIntersection(array $keys, string $aggregate = 'sum'): int
    {
        $payload = [$this->key, \count($keys)];
        $weights = [];

        if (\count(\array_filter(\array_keys($keys), 'is_string'))) {
            foreach ($keys as $key => $weight) {
                $payload[] = $key;
                $weights[] = $weight;
            }
        } else {
            foreach ($keys as $key) {
                $payload[] = $key;
            }
        }

        if (\count($weights) > 0) {
            $payload[] = 'WEIGHTS';

            foreach ($weights as $weight) {
                $payload[] = $weight;
            }
        }

        if (\strtolower($aggregate) !== 'sum') {
            $payload[] = 'AGGREGATE';
            $payload[] = $aggregate;
        }

        return $this->client->execute('zinterstore', ...$payload);
    }

    public function countLexicographicRange(LexBoundary $min, LexBoundary $max): int
    {
        return $this->client->execute('zlexcount', $this->key, $min->toQuery(), $max->toQuery());
    }

    public function getRank(string $member): ?int
    {
        return $this->client->execute('zrank', $this->key, $member);
    }

    public function remove(string $member, string ...$members): int
    {
        return $this->client->execute('zrem', $this->key, $member, ...$members);
    }

    public function removeLexicographicRange(LexBoundary $min, LexBoundary $max): int
    {
        return $this->client->execute('zremrangebylex', $this->key, $min->toQuery(), $max->toQuery());
    }

    public function removeRankRange(int $start, int $stop): int
    {
        return $this->client->execute('zremrangebyrank', $this->key, $start, $stop);
    }

    public function removeRangeByScore(ScoreBoundary $min, ScoreBoundary $max): int
    {
        return $this->client->execute('zremrangebyscore', $this->key, $min->toQuery(), $max->toQuery());
    }

    public function getReversedRank(string $member): ?int
    {
        return $this->client->execute('zrevrank', $this->key, $member);
    }

    /**
     * @return \Traversable<array{string, float}>
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

            /** @var list<string> $keys */
            [$cursor, $keys] = $this->client->execute('ZSCAN', ...$query);

            $count = \count($keys);
            \assert($count % 2 === 0);

            for ($i = 0; $i < $count; $i += 2) {
                yield [$keys[$i], (float) $keys[$i + 1]];
            }
        } while ($cursor !== '0');
    }

    public function getScore(string $member): ?float
    {
        return (float) $this->client->execute('zscore', $this->key, $member);
    }

    /**
     * @param string[] $keys
     */
    public function storeUnion(array $keys, string $aggregate = 'sum'): int
    {
        $payload = [$this->key, \count($keys)];
        $weights = [];

        if (\count(\array_filter(\array_keys($keys), 'is_string'))) {
            foreach ($keys as $key => $weight) {
                $payload[] = $key;
                $weights[] = $weight;
            }
        } else {
            foreach ($keys as $key) {
                $payload[] = $key;
            }
        }

        if (\count($weights) > 0) {
            $payload[] = 'WEIGHTS';

            foreach ($weights as $weight) {
                $payload[] = $weight;
            }
        }

        if (\strtolower($aggregate) !== 'sum') {
            $payload[] = 'AGGREGATE';
            $payload[] = $aggregate;
        }

        return $this->client->execute('zunionstore', ...$payload);
    }

    /**
     * @link https://redis.io/commands/sort
     */
    public function sort(?SortOptions $options = null): array
    {
        return $this->client->execute('SORT', $this->key, ...($options ?? new SortOptions)->toQuery());
    }
}
