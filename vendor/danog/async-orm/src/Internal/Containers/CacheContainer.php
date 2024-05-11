<?php declare(strict_types=1);

/**
 * This file is part of AsyncOrm.
 * AsyncOrm is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General private License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * AsyncOrm is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General private License for more details.
 * You should have received a copy of the GNU General private License along with AsyncOrm.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Containers;

use Amp\Sync\LocalMutex;
use danog\AsyncOrm\DbArray;
use Revolt\EventLoop;
use Traversable;

/**
 * @template TKey as array-key
 * @template TValue
 * @internal
 */
final class CacheContainer
{
    /**
     * @var array<TKey, TValue>
     */
    private array $cache = [];
    /**
     * @var array<TKey, int|true>
     */
    private array $ttl = [];

    /**
     * Cache cleanup watcher ID.
     */
    private ?string $cacheCleanupId = null;

    private LocalMutex $mutex;

    public function __construct(
        /** @var DbArray<TKey, TValue> */
        public DbArray $inner,
        public int $cacheTtl
    ) {
        $this->mutex = new LocalMutex;
    }
    public function __sleep()
    {
        return ['inner'];
    }
    public function __wakeup(): void
    {
        $this->mutex = new LocalMutex;
    }

    public function startCacheCleanupLoop(): void
    {
        if ($this->cacheCleanupId !== null) {
            EventLoop::cancel($this->cacheCleanupId);
        }
        $this->cacheCleanupId = EventLoop::repeat(
            \max(1, $this->cacheTtl / 5),
            fn () => $this->flushCache(),
        );
    }
    public function stopCacheCleanupLoop(): void
    {
        if ($this->cacheCleanupId !== null) {
            EventLoop::cancel($this->cacheCleanupId);
            $this->cacheCleanupId = null;
        }
    }

    /**
     * @param TKey $index
     * @return TValue
     */
    public function get(string|int $index): mixed
    {
        if (isset($this->ttl[$index])) {
            if ($this->ttl[$index] !== true) {
                $this->ttl[$index] = \time() + $this->cacheTtl;
            }
            return $this->cache[$index];
        }

        $result = $this->inner->get($index);
        /** @psalm-suppress ParadoxicalCondition Concurrency */
        if (isset($this->ttl[$index])) {
            if ($this->ttl[$index] !== true) {
                $this->ttl[$index] = \time() + $this->cacheTtl;
            }
            return $this->cache[$index];
        }

        $this->ttl[$index] = \time() + $this->cacheTtl;
        $this->cache[$index] = $result;

        return $result;
    }

    /**
     * @param TKey $key
     * @param ?TValue $value
     */
    public function set(string|int $key, mixed $value): void
    {
        if (isset($this->ttl[$key]) && $this->cache[$key] === $value) {
            return;
        }
        $this->cache[$key] = $value;
        $this->ttl[$key] = true;
    }

    /** @return Traversable<TKey, TValue> */
    public function getIterator(): Traversable
    {
        $this->flushCache();
        return $this->inner->getIterator();
    }

    public function count(): int
    {
        $this->flushCache();
        return $this->inner->count();
    }

    public function clear(): void
    {
        $lock = $this->mutex->acquire();
        $this->cache = [];
        $this->ttl = [];
        $lock->release();

        $this->inner->clear();
    }

    /**
     * Flush all flushable keys.
     */
    public function flushCache(): void
    {
        $lock = $this->mutex->acquire();
        try {
            $updatedValues = [];
            $newValues = [];
            $newTtl = [];
            $now = \time();
            foreach ($this->ttl as $key => $ttl) {
                if ($ttl === true) {
                    $updatedValues[$key] = $this->cache[$key];
                    if ($this->cache[$key] === null) {
                        $this->inner->unset($key);
                    } else {
                        $this->inner->set($key, $this->cache[$key]);
                    }
                } elseif ($ttl > $now) {
                    $newTtl[$key] = $ttl;
                    $newValues[$key] = $this->cache[$key];
                }
            }
            foreach ($updatedValues as $key => $value) {
                if (($newValues[$key] = $this->cache[$key]) === $value) {
                    // The value we wrote is equal to the latest value,
                    // turn into a read-cache entry
                    $newTtl[$key] = \time() + $this->cacheTtl;
                } else {
                    // The value we wrote is already old,
                    // keep it a write-cache entry to re-write it later
                    $newTtl[$key] = $this->ttl[$key];
                }
            }
            $this->ttl = $newTtl;
            $this->cache = $newValues;
        } finally {
            EventLoop::queue($lock->release(...));
        }
    }
}
