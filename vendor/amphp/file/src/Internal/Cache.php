<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Revolt\EventLoop;

/** @internal */
final class Cache
{
    private readonly object $sharedState;

    private readonly string $ttlWatcherId;

    /**
     * @param int      $gcInterval The frequency in milliseconds at which expired cache entries should be garbage
     *     collected.
     * @param int|null $maxSize The maximum size of cache array (number of elements).
     */
    public function __construct(int $gcInterval = 1000, private readonly ?int $maxSize = null)
    {
        // By using a shared state object we're able to use `__destruct()` for "normal" garbage collection of both this
        // instance and the loop's watcher. Otherwise, this object could only be GC'd when the TTL watcher was cancelled
        // at the loop layer.
        $this->sharedState = $sharedState = new class {
            /** @var array[] */
            public array $cache = [];
            /** @var int[] */
            public array $cacheTimeouts = [];

            public bool $isSortNeeded = false;

            public function collectGarbage(): void
            {
                $now = \time();

                if ($this->isSortNeeded) {
                    \asort($this->cacheTimeouts);
                    $this->isSortNeeded = false;
                }

                foreach ($this->cacheTimeouts as $key => $expiry) {
                    if ($now <= $expiry) {
                        break;
                    }

                    unset(
                        $this->cache[$key],
                        $this->cacheTimeouts[$key]
                    );
                }
            }
        };

        $this->ttlWatcherId = EventLoop::repeat($gcInterval, $sharedState->collectGarbage(...));

        EventLoop::unreference($this->ttlWatcherId);
    }

    public function __destruct()
    {
        $this->sharedState->cache = [];
        $this->sharedState->cacheTimeouts = [];

        EventLoop::cancel($this->ttlWatcherId);
    }

    public function get(string $key): ?array
    {
        if (!isset($this->sharedState->cache[$key])) {
            return null;
        }

        if (isset($this->sharedState->cacheTimeouts[$key]) && \time() > $this->sharedState->cacheTimeouts[$key]) {
            unset(
                $this->sharedState->cache[$key],
                $this->sharedState->cacheTimeouts[$key]
            );

            return null;
        }

        return $this->sharedState->cache[$key];
    }

    public function set(string $key, array $value, ?int $ttl = null): void
    {
        if ($ttl === null) {
            unset($this->sharedState->cacheTimeouts[$key]);
        } elseif ($ttl >= 0) {
            $expiry = \time() + $ttl;
            $this->sharedState->cacheTimeouts[$key] = $expiry;
            $this->sharedState->isSortNeeded = true;
        } else {
            throw new \Error("Invalid cache TTL ({$ttl}; integer >= 0 or null required");
        }

        unset($this->sharedState->cache[$key]);
        if (\count($this->sharedState->cache) === $this->maxSize) {
            \array_shift($this->sharedState->cache);
        }

        $this->sharedState->cache[$key] = $value;
    }

    public function delete(string $key): bool
    {
        $exists = isset($this->sharedState->cache[$key]);

        unset(
            $this->sharedState->cache[$key],
            $this->sharedState->cacheTimeouts[$key]
        );

        return $exists;
    }
}
