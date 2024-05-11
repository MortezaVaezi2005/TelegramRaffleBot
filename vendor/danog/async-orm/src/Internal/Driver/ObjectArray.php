<?php declare(strict_types=1);

/**
 * Copyright 2024 Daniil Gentili.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Driver;

use AssertionError;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\Internal\Containers\ObjectContainer;
use danog\AsyncOrm\Settings\DriverSettings;
use Traversable;

/**
 * Object caching proxy.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue as DbObject
 *
 * @extends DbArray<TKey, TValue>
 */
final class ObjectArray extends DbArray
{
    /** @var ObjectContainer<TKey, TValue> */
    private readonly ObjectContainer $cache;

    /**
     * Get instance.
     *
     * @template TTKey as array-key
     * @template TTValue as DbObject
     *
     * @param DbArray<TTKey, TValue>|null $previous
     * @return DbArray<TTKey, TValue>
     */
    public static function getInstance(DbArrayBuilder $config, DbArray|null $previous): DbArray
    {
        $new = $config->settings->getDriverClass();
        \assert($config->settings instanceof DriverSettings);
        if ($previous === null) {
            $previous = new self($new::getInstance($config, null), $config, $config->settings->cacheTtl);
        } elseif ($previous instanceof self) {
            $previous->cache->inner = $new::getInstance($config, $previous->cache->inner);
            $previous->cache->config = $config;
            $previous->cache->cacheTtl = $config->settings->cacheTtl;
        } else {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Impossible!");
            // @codeCoverageIgnoreEnd
        }
        $previous->cache->startCacheCleanupLoop();
        /** @var DbArray<TTKey, TValue> */
        return $previous;
    }

    /** @param DbArray<TKey, TValue> $inner */
    public function __construct(DbArray $inner, DbArrayBuilder $config, int $cacheTtl)
    {
        $this->cache = new ObjectContainer($inner, $config, $cacheTtl);
    }

    public function __destruct()
    {
        $this->cache->stopCacheCleanupLoop();
    }

    /** @api */
    public function count(): int
    {
        return $this->cache->count();
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    /**
     * @param TKey $key
     * @return TValue
     */
    public function get(mixed $key): mixed
    {
        return $this->cache->get($key);
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function set(string|int $key, mixed $value): void
    {
        $this->cache->set($key, $value);
    }

    /** @param TKey $key */
    public function unset(string|int $key): void
    {
        $this->cache->unset($key);
    }

    /** @return Traversable<TKey, TValue> */
    public function getIterator(): Traversable
    {
        return $this->cache->getIterator();
    }
}
