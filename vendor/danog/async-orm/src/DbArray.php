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
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * DB array interface.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements Traversable<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 *
 * @api
 */
abstract class DbArray implements Countable, ArrayAccess, Traversable, IteratorAggregate
{
    /**
     * Check if element exists.
     *
     * @param TKey $key
     */
    final public function isset(string|int $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @param TKey $offset
     * @return TValue
     */
    final public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * @param TKey $offset
     */
    final public function offsetExists(mixed $offset): bool
    {
        return $this->isset($offset);
    }

    /**
     * @param TKey $offset
     * @param TValue $value
     */
    final public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * @param TKey $offset
     */
    final public function offsetUnset(mixed $offset): void
    {
        $this->unset($offset);
    }

    public function getArrayCopy(): array
    {
        return \iterator_to_array($this->getIterator());
    }

    /**
     * Unset element.
     *
     * @param TKey $key
     */
    abstract public function unset(string|int $key): void;
    /**
     * Set element.
     *
     * @param TKey   $key
     * @param TValue $value
     */
    abstract public function set(string|int $key, mixed $value): void;
    /**
     * Get element.
     *
     * @param TKey   $key
     * @return ?TValue
     */
    abstract public function get(string|int $key): mixed;
    /**
     * Clear all elements.
     */
    abstract public function clear(): void;

    /**
     * Get instance.
     *
     * @template TTKey as array-key
     * @template TTValue as DbObject
     *
     * @param DbArray<TTKey, TValue>|null $previous
     * @return DbArray<TTKey, TValue>
     */
    abstract public static function getInstance(DbArrayBuilder $config, self|null $previous): self;
}
