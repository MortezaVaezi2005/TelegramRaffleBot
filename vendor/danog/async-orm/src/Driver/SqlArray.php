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

use Amp\Sql\SqlConnectionPool;
use Amp\Sql\SqlResult;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Serializer;

/**
 * Generic SQL database backend.
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 *
 * @api
 */
abstract class SqlArray extends DriverArray
{
    /**
     * @psalm-suppress ConstructorSignatureMismatch
     * @param Serializer<TValue> $serializer
     */
    protected function __construct(
        DbArrayBuilder $config,
        Serializer $serializer,
        protected readonly SqlConnectionPool $db,
        private readonly string $get,
        private readonly string $set,
        private readonly string $unset,
        private readonly string $count,
        private readonly string $iterate,
        private readonly string $clear,
    ) {
        parent::__construct($config, $serializer);
    }

    /**
     * Get iterator.
     *
     * @return \Traversable<array-key, mixed>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->execute($this->iterate) as ['key' => $key, 'value' => $value]) {
            yield $key => $this->serializer->deserialize($value);
        }
    }

    public function get(mixed $key): mixed
    {
        $key = (string) $key;

        $row = $this->execute($this->get, ['index' => $key])->fetchRow();
        if ($row === null) {
            return null;
        }

        $value = $this->serializer->deserialize($row['value']);

        return $value;
    }

    public function set(string|int $key, mixed $value): void
    {
        $key = (string) $key;
        /** @var scalar */
        $value = $this->serializer->serialize($value);

        $this->execute(
            $this->set,
            [
                'index' => $key,
                'value' => $value,
            ],
        );
    }

    /**
     * Unset value for an offset.
     *
     * @link https://php.net/manual/en/arrayiterator.offsetunset.php
     */
    public function unset(string|int $key): void
    {
        $key = (string) $key;

        $this->execute(
            $this->unset,
            ['index' => $key],
        );
    }

    /**
     * Count elements.
     *
     * @link https://php.net/manual/en/arrayiterator.count.php
     * @return int The number of elements or public properties in the associated
     *             array or object, respectively.
     */
    public function count(): int
    {
        $row = $this->execute($this->count);
        $res = $row->fetchRow();
        \assert($res !== null && isset($res['count']) && \is_int($res['count']));
        return $res['count'];
    }

    /**
     * Clear all elements.
     */
    public function clear(): void
    {
        $this->execute($this->clear);
    }

    /**
     * Perform async request to db.
     * @param array<string, scalar> $params
     */
    protected function execute(string $sql, array $params = []): SqlResult
    {
        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * Import data from existing table.
     */
    abstract protected function importFromTable(string $fromTable): void;
}
