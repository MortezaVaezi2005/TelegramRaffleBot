<?php declare(strict_types=1);
/** @noinspection DuplicatedCode */

namespace Amp\Redis;

use Amp\Cache\AtomicCache;
use Amp\Cache\LocalCache;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\Command\RedisHyperLogLog;
use Amp\Redis\Command\RedisList;
use Amp\Redis\Command\RedisMap;
use Amp\Redis\Command\RedisSet;
use Amp\Redis\Command\RedisSortedSet;
use Amp\Redis\Connection\RedisLink;
use Amp\Redis\Protocol\RedisError;
use Amp\Sync\LocalKeyedMutex;
use function Amp\Redis\Internal\toMap;

final class RedisClient
{
    use ForbidCloning;
    use ForbidSerialization;

    private AtomicCache $evalCache;

    public function __construct(
        private readonly RedisLink $link
    ) {
        $this->evalCache = $this->createCache();
    }

    public function getHyperLogLog(string $key): RedisHyperLogLog
    {
        return new RedisHyperLogLog($this, $key);
    }

    public function getList(string $key): RedisList
    {
        return new RedisList($this, $key);
    }

    public function getMap(string $key): RedisMap
    {
        return new RedisMap($this, $key);
    }

    public function getSet(string $key): RedisSet
    {
        return new RedisSet($this, $key);
    }

    public function getSortedSet(string $key): RedisSortedSet
    {
        return new RedisSortedSet($this, $key);
    }

    public function execute(string $command, string|int|float ...$args): mixed
    {
        return $this->link->execute($command, $args)->unwrap();
    }

    /**
     * @link https://redis.io/commands/del
     */
    public function delete(string $key, string ...$keys): int
    {
        return $this->execute('del', $key, ...$keys);
    }

    /**
     * @link https://redis.io/commands/dump
     */
    public function dump(string $key): string
    {
        return $this->execute('dump', $key);
    }

    /**
     * @link https://redis.io/commands/exists
     */
    public function has(string $key): bool
    {
        return (bool) $this->execute('exists', $key);
    }

    /**
     * @link https://redis.io/commands/expire
     */
    public function expireIn(string $key, int $seconds): bool
    {
        return (bool) $this->execute('expire', $key, $seconds);
    }

    /**
     * @link https://redis.io/commands/pexpire
     */
    public function expireInMillis(string $key, int $millis): bool
    {
        return (bool) $this->execute('pexpire', $key, $millis);
    }

    /**
     * @link https://redis.io/commands/expireat
     */
    public function expireAt(string $key, int $timestamp): bool
    {
        return (bool) $this->execute('expireat', $key, $timestamp);
    }

    /**
     * @link https://redis.io/commands/pexpireat
     */
    public function expireAtMillis(string $key, int $timestamp): bool
    {
        return (bool) $this->execute('pexpireat', $key, $timestamp);
    }

    /**
     * @link https://redis.io/commands/keys
     *
     * @see RedisClient::scan()
     */
    public function getKeys(string $pattern = '*'): array
    {
        return $this->execute('keys', $pattern);
    }

    /**
     * @link https://redis.io/commands/move
     */
    public function move(string $key, int $db): bool
    {
        return (bool) $this->execute('move', $key, $db);
    }

    /**
     * @link https://redis.io/commands/object
     */
    public function getObjectRefcount(string $key): int
    {
        return $this->execute('object', 'refcount', $key);
    }

    /**
     * @link https://redis.io/commands/object
     */
    public function getObjectEncoding(string $key): string
    {
        return $this->execute('object', 'encoding', $key);
    }

    /**
     * @link https://redis.io/commands/object
     */
    public function getObjectIdletime(string $key): int
    {
        return $this->execute('object', 'idletime', $key);
    }

    /**
     * @link https://redis.io/commands/persist
     */
    public function persist(string $key): bool
    {
        return (bool) $this->execute('persist', $key);
    }

    /**
     * @link https://redis.io/commands/randomkey
     */
    public function getRandomKey(): ?string
    {
        return $this->execute('randomkey');
    }

    /**
     * @link https://redis.io/commands/rename
     */
    public function rename(string $key, string $newKey): void
    {
        $this->execute('rename', $key, $newKey);
    }

    /**
     * @link https://redis.io/commands/renamenx
     */
    public function renameWithoutOverwrite(string $key, string $newKey): void
    {
        $this->execute('renamenx', $key, $newKey);
    }

    /**
     * @link https://redis.io/commands/restore
     */
    public function restore(string $key, string $serializedValue, int $ttl = 0): void
    {
        $this->execute('restore', $key, $ttl, $serializedValue);
    }

    /**
     * @return \Traversable<string>
     *
     * @link https://redis.io/commands/scan
     */
    public function scan(?string $pattern = null, ?int $count = null): \Traversable
    {
        $cursor = 0;

        do {
            $query = [$cursor];

            if ($pattern !== null) {
                $query[] = 'MATCH';
                $query[] = $pattern;
            }

            if ($count !== null) {
                $query[] = 'COUNT';
                $query[] = $count;
            }

            [$cursor, $keys] = $this->execute('SCAN', ...$query);

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * @link https://redis.io/commands/ttl
     */
    public function getTtl(string $key): int
    {
        return $this->execute('ttl', $key);
    }

    /**
     * @link https://redis.io/commands/pttl
     */
    public function getTtlInMillis(string $key): int
    {
        return $this->execute('pttl', $key);
    }

    /**
     * @link https://redis.io/commands/type
     */
    public function getType(string $key): string
    {
        return $this->execute('type', $key);
    }

    /**
     * @link https://redis.io/commands/append
     */
    public function append(string $key, string $value): int
    {
        return $this->execute('append', $key, $value);
    }

    /**
     * @link https://redis.io/commands/bitcount
     */
    public function countBits(string $key, ?int $start = null, ?int $end = null): int
    {
        $cmd = [$key];

        if (isset($start, $end)) {
            $cmd[] = $start;
            $cmd[] = $end;
        } elseif (isset($start) || isset($end)) {
            throw new \Error('Start and end must both be set or unset in countBits(), got start = ' . $start . ' and end = ' . $end);
        }

        return $this->execute('bitcount', ...$cmd);
    }

    /**
     * @link https://redis.io/commands/bitop
     */
    public function storeBitwiseAnd(string $destination, string $key, string ...$keys): int
    {
        return $this->execute('bitop', 'and', $destination, $key, ...$keys);
    }

    /**
     * @link https://redis.io/commands/bitop
     */
    public function storeBitwiseOr(string $destination, string $key, string ...$keys): int
    {
        return $this->execute('bitop', 'or', $destination, $key, ...$keys);
    }

    /**
     * @link https://redis.io/commands/bitop
     */
    public function storeBitwiseXor(string $destination, string $key, string ...$keys): int
    {
        return $this->execute('bitop', 'xor', $destination, $key, ...$keys);
    }

    /**
     * @link https://redis.io/commands/bitop
     */
    public function storeBitwiseNot(string $destination, string $key): int
    {
        return $this->execute('bitop', 'not', $destination, $key);
    }

    /**
     * @link https://redis.io/commands/bitpos
     */
    public function getBitPosition(string $key, bool $bit, ?int $start = null, ?int $end = null): int
    {
        $payload = [$key, $bit ? 1 : 0];

        if ($start !== null) {
            $payload[] = $start;

            if ($end !== null) {
                $payload[] = $end;
            }
        }

        return $this->execute('bitpos', ...$payload);
    }

    /**
     * @link https://redis.io/commands/decrby
     */
    public function decrement(string $key, int $decrement = 1): int
    {
        if ($decrement === 1) {
            return $this->execute('decr', $key);
        }

        return $this->execute('decrby', $key, $decrement);
    }

    /**
     * @link https://redis.io/commands/get
     */
    public function get(string $key): ?string
    {
        return $this->execute('get', $key);
    }

    /**
     * @link https://redis.io/commands/getbit
     */
    public function getBit(string $key, int $offset): bool
    {
        return (bool) $this->execute('getbit', $key, $offset);
    }

    /**
     * @link https://redis.io/commands/getrange
     */
    public function getRange(string $key, int $start = 0, int $end = -1): string
    {
        return $this->execute('getrange', $key, $start, $end);
    }

    /**
     * @link https://redis.io/commands/getset
     */
    public function getAndSet(string $key, string $value): string
    {
        return $this->execute('getset', $key, $value);
    }

    /**
     * @link https://redis.io/commands/incrby
     */
    public function increment(string $key, int $increment = 1): int
    {
        if ($increment === 1) {
            return $this->execute('incr', $key);
        }

        return $this->execute('incrby', $key, $increment);
    }

    /**
     * @link https://redis.io/commands/incrbyfloat
     */
    public function incrementByFloat(string $key, float $increment): float
    {
        return (float) $this->execute('incrbyfloat', $key, $increment);
    }

    /**
     * @return array<string|null>
     *
     * @link https://redis.io/commands/mget
     */
    public function getMultiple(string $key, string ...$keys): array
    {
        \array_unshift($keys, $key);

        return \array_combine($keys, $this->execute('mget', ...$keys));
    }

    /**
     * @link https://redis.io/commands/mset
     *
     * @param array<string, int|float|string> $data
     */
    public function setMultiple(array $data): void
    {
        $payload = [];

        foreach ($data as $key => $value) {
            $payload[] = $key;
            $payload[] = $value;
        }

        $this->execute('mset', ...$payload);
    }

    /**
     * @link https://redis.io/commands/msetnx
     *
     * @param array<string, int|float|string> $data
     */
    public function setMultipleWithoutOverwrite(array $data): void
    {
        $payload = [];

        foreach ($data as $key => $value) {
            $payload[] = $key;
            $payload[] = $value;
        }

        $this->execute('msetnx', ...$payload);
    }

    /**
     * @link https://redis.io/commands/setnx
     */
    public function setWithoutOverwrite(string $key, string $value): bool
    {
        return (bool) $this->execute('setnx', $key, $value);
    }

    /**
     * @link https://redis.io/commands/set/
     */
    public function set(string $key, string $value, ?SetOptions $options = null): bool
    {
        $options ??= new SetOptions();
        return (bool) $this->execute('set', $key, $value, ...$options->toQuery());
    }

    /**
     * @link https://redis.io/commands/setbit
     */
    public function setBit(string $key, int $offset, bool $value): int
    {
        return $this->execute('setbit', $key, $offset, (int) $value);
    }

    /**
     * @param mixed  $value
     *
     * @link https://redis.io/commands/setrange
     */
    public function setRange(string $key, int $offset, string $value): int
    {
        return $this->execute('setrange', $key, $offset, $value);
    }

    /**
     * @link https://redis.io/commands/strlen
     */
    public function getLength(string $key): int
    {
        return $this->execute('strlen', $key);
    }

    /**
     * @link https://redis.io/commands/publish
     */
    public function publish(string $channel, string $message): int
    {
        return $this->execute('publish', $channel, $message);
    }

    /**
     * @param string $pattern
     *
     * @link https://redis.io/commands/pubsub
     */
    public function getActiveChannels(?string $pattern = null): array
    {
        $payload = ['channels'];

        if ($pattern !== null) {
            $payload[] = $pattern;
        }

        return $this->execute('pubsub', ...$payload);
    }

    /**
     * @return int[]
     *
     * @link https://redis.io/commands/pubsub
     */
    public function getNumberOfSubscriptions(string ...$channels): array
    {
        return toMap($this->execute('pubsub', 'numsub', ...$channels));
    }

    /**
     * @link https://redis.io/commands/pubsub
     */
    public function getNumberOfPatternSubscriptions(): int
    {
        return $this->execute('pubsub', 'numpat');
    }

    /**
     * @link https://redis.io/commands/ping
     */
    public function ping(): void
    {
        $this->execute('ping');
    }

    /**
     * @link https://redis.io/commands/quit
     */
    public function quit(): void
    {
        $this->execute('quit');
    }

    /**
     * @link https://redis.io/commands/bgrewriteaof
     */
    public function rewriteAofAsync(): void
    {
        $this->execute('bgrewriteaof');
    }

    /**
     * @link https://redis.io/commands/bgsave
     */
    public function saveAsync(): void
    {
        $this->execute('bgsave');
    }

    /**
     * @link https://redis.io/commands/client-getname
     */
    public function getName(): ?string
    {
        return $this->execute('client', 'getname');
    }

    /**
     * @link https://redis.io/commands/client-pause
     */
    public function pauseMillis(int $timeInMillis): void
    {
        $this->execute('client', 'pause', $timeInMillis);
    }

    /**
     * @link https://redis.io/commands/client-setname
     */
    public function setName(string $name): void
    {
        $this->execute('client', 'setname', $name);
    }

    /**
     * @link https://redis.io/commands/config-get
     */
    public function getConfig(string $parameter): array
    {
        return $this->execute('config', 'get', $parameter);
    }

    /**
     * @link https://redis.io/commands/config-resetstat
     */
    public function resetStatistics(): void
    {
        $this->execute('config', 'resetstat');
    }

    /**
     * @link https://redis.io/commands/config-rewrite
     */
    public function rewriteConfig(): void
    {
        $this->execute('config', 'rewrite');
    }

    /**
     * @link https://redis.io/commands/config-set
     */
    public function setConfig(string $parameter, string $value): void
    {
        $this->execute('config', 'set', $parameter, $value);
    }

    /**
     * @link https://redis.io/commands/dbsize
     */
    public function getDatabaseSize(): int
    {
        return $this->execute('dbsize');
    }

    /**
     * @link https://redis.io/commands/flushall
     */
    public function flushAll(): void
    {
        $this->execute('flushall');
    }

    /**
     * @link https://redis.io/commands/flushdb
     */
    public function flushDatabase(): void
    {
        $this->execute('flushdb');
    }

    /**
     * @link https://redis.io/commands/lastsave
     */
    public function getLastSave(): int
    {
        return $this->execute('lastsave');
    }

    /**
     * @link https://redis.io/commands/role
     */
    public function getRole(): array
    {
        return $this->execute('role');
    }

    /**
     * @link https://redis.io/commands/save
     */
    public function save(): void
    {
        $this->execute('save');
    }

    /**
     * @link https://redis.io/commands/shutdown
     */
    public function shutdownWithSave(): string
    {
        return $this->execute('shutdown', 'save');
    }

    /**
     * @link https://redis.io/commands/shutdown
     */
    public function shutdownWithoutSave(): string
    {
        return $this->execute('shutdown', 'nosave');
    }

    /**
     * @link https://redis.io/commands/shutdown
     */
    public function shutdown(): string
    {
        return $this->execute('shutdown');
    }

    /**
     * @link https://redis.io/commands/slaveof
     */
    public function enableReplication(string $host, int $port): void
    {
        $this->execute('slaveof', $host, $port);
    }

    /**
     * @link https://redis.io/commands/slaveof
     */
    public function disableReplication(): void
    {
        $this->execute('slaveof', 'no', 'one');
    }

    /**
     * @param int $count
     *
     * @link https://redis.io/commands/slowlog
     */
    public function getSlowlog(?int $count = null): array
    {
        $payload = ['get'];

        if ($count !== null) {
            $payload[] = $count;
        }

        return $this->execute('slowlog', ...$payload);
    }

    /**
     * @link https://redis.io/commands/slowlog
     */
    public function getSlowlogLength(): int
    {
        return $this->execute('slowlog', 'len');
    }

    /**
     * @link https://redis.io/commands/slowlog
     */
    public function resetSlowlog(): void
    {
        $this->execute('slowlog', 'reset');
    }

    /**
     * @link https://redis.io/commands/time
     */
    public function getTime(): array
    {
        return $this->execute('time');
    }

    /**
     * @link https://redis.io/commands/script-exists
     */
    public function hasScript(string $sha1): bool
    {
        $array = $this->execute('script', 'exists', $sha1);
        return (bool) ($array[0] ?? false);
    }

    /**
     * @link https://redis.io/commands/script-flush
     */
    public function flushScripts(): void
    {
        $this->evalCache = $this->createCache(); // same as internal redis behavior

        $this->execute('script', 'flush');
    }

    /**
     * @link https://redis.io/commands/script-kill
     */
    public function killScript(): void
    {
        $this->execute('script', 'kill');
    }

    /**
     * @link https://redis.io/commands/script-load
     */
    public function loadScript(string $script): string
    {
        return $this->execute('script', 'load', $script);
    }

    /**
     * @link https://redis.io/commands/echo
     */
    public function echo(string $text): string
    {
        return $this->execute('echo', $text);
    }

    /**
     * @param array<array-key, string> $keys
     * @param array<array-key, int|float|string> $args
     *
     * @link https://redis.io/commands/eval
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        $sha1 = $this->evalCache->computeIfAbsent($script, fn (string $value) => \sha1($value));

        $response = $this->link->execute('evalsha', [$sha1, \count($keys), ...$keys, ...$args]);

        if ($response instanceof RedisError && $response->getKind() === 'NOSCRIPT') {
            return $this->execute('eval', $script, \count($keys), ...$keys, ...$args);
        }

        return $response->unwrap();
    }

    public function select(int $database): void
    {
        $this->execute('select', $database);
    }

    private function createCache(): AtomicCache
    {
        return new AtomicCache(new LocalCache(1000, 60), new LocalKeyedMutex());
    }
}
