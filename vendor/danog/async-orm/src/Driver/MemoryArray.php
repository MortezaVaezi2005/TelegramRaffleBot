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

namespace danog\AsyncOrm\Driver;

use ArrayIterator;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;

/**
 * Memory database backend.
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DbArray<TKey, TValue>
 * @api
 */
final class MemoryArray extends DbArray
{
    public function __construct(
        /** @var array<TKey, TValue> */
        private array $data
    ) {
    }

    public static function getInstance(DbArrayBuilder $config, DbArray|null $previous): DbArray
    {
        if ($previous instanceof self) {
            return $previous;
        }
        if ($previous instanceof DbArray) {
            $temp = $previous->getArrayCopy();
            $previous->clear();
            $previous = $temp;
        } else {
            $previous = [];
        }
        return new self($previous);
    }

    public function set(string|int $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    public function get(string|int $key): mixed
    {
        return $this->data[$key] ?? null;
    }
    public function unset(string|int $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function count(): int
    {
        return \count($this->data);
    }

    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->data);
    }

    public function getArrayCopy(): array
    {
        return $this->data;
    }
}
