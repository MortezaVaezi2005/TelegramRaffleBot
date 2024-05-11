<?php declare(strict_types=1);

namespace Amp\Redis\Sync;

use Amp\Redis\RedisClient;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\Sync\Parcel;
use Amp\Sync\ParcelException;

/**
 * @template T
 * @implements Parcel<T>
 */
final class RedisParcel implements Parcel
{
    public static function create(
        RedisMutex $mutex,
        string $key,
        mixed $value,
        ?Serializer $serializer = null,
    ): self {
        return (new self($mutex, $key, $serializer))->init($value);
    }

    public static function use(
        RedisMutex $mutex,
        string $key,
        ?Serializer $serializer = null,
    ): self {
        return (new self($mutex, $key, $serializer))->open();
    }

    private readonly RedisClient $redis;

    private readonly Serializer $serializer;

    private function __construct(
        private readonly RedisMutex $mutex,
        private readonly string $key,
        ?Serializer $serializer = null,
    ) {
        $this->redis = $mutex->getClient();
        $this->serializer = $serializer ?? new NativeSerializer();
    }

    private function init(mixed $value): self
    {
        $value = $this->serializer->serialize($value);

        $lock = $this->mutex->acquire($this->key);

        try {
            $this->redis->set($this->key, $value);
        } finally {
            $lock->release();
        }

        return $this;
    }

    private function open(): self
    {
        if (!$this->redis->get($this->key)) {
            throw new ParcelException('Could not open parcel: key not found');
        }

        return $this;
    }

    public function getClient(): RedisClient
    {
        return $this->mutex->getClient();
    }

    public function unwrap(): mixed
    {
        $value = $this->redis->get($this->key)
            ?? throw new ParcelException('Could not unwrap parcel: key not found');

        return $this->serializer->unserialize($value);
    }

    public function synchronized(\Closure $closure): mixed
    {
        $lock = $this->mutex->acquire($this->key);

        try {
            $result = $closure($this->unwrap());
            $this->redis->set($this->key, $this->serializer->serialize($result));
        } finally {
            $lock->release();
        }

        return $result;
    }
}
