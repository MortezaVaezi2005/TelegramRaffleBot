<?php declare(strict_types=1);

namespace Amp\Redis;

use Amp\Cache\Cache;
use Amp\Cache\CacheException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;

/**
 * @template T
 * @implements Cache<T>
 */
final class RedisCache implements Cache
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Serializer $serializer = new NativeSerializer(),
    ) {
    }

    public function get(string $key): mixed
    {
        try {
            $data = $this->redis->get($key);
            if ($data === null) {
                return null;
            }

            return $this->serializer->unserialize($data);
        } catch (RedisException $e) {
            throw new CacheException("Fetching '$key' from cache failed", 0, $e);
        }
    }

    public function set(string $key, mixed $value, int $ttl = null): void
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \Error('Invalid TTL: ' . $ttl);
        }

        if ($ttl === 0) {
            return; // expires immediately
        }

        try {
            $options = new SetOptions;

            if ($ttl !== null) {
                $options = $options->withTtl($ttl);
            }

            $this->redis->set($key, $this->serializer->serialize($value), $options);
        } catch (RedisException $e) {
            throw new CacheException("Storing '{$key}' to cache failed", 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        try {
            return (bool) $this->redis->delete($key);
        } catch (RedisException $e) {
            throw new CacheException("Deleting '{$key}' from cache failed", 0, $e);
        }
    }
}
