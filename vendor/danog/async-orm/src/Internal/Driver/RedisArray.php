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

use Amp\Redis\Connection\ReconnectingRedisLink;
use Amp\Redis\RedisClient;
use Amp\Sync\LocalKeyedMutex;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Driver\DriverArray;
use danog\AsyncOrm\Internal\Serializer\BoolString;
use danog\AsyncOrm\Internal\Serializer\FloatString;
use danog\AsyncOrm\Internal\Serializer\IntString;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\RedisSettings;
use danog\AsyncOrm\ValueType;
use Revolt\EventLoop;

use function Amp\Redis\createRedisConnector;

/**
 * Redis database backend.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 */
final class RedisArray extends DriverArray
{
    /** @var array<RedisClient> */
    private static array $connections = [];
    private static ?LocalKeyedMutex $mutex = null;

    private readonly RedisClient $db;
    private readonly bool $castToInt;

    /**
     * @api
     * @param Serializer<TValue> $serializer
     */
    public function __construct(DbArrayBuilder $config, Serializer $serializer)
    {
        /** @var Serializer<TValue> */
        $serializer = match ($config->valueType) {
            ValueType::INT => new IntString,
            ValueType::FLOAT => new FloatString,
            ValueType::BOOL => new BoolString,
            ValueType::SCALAR, ValueType::OBJECT => $serializer,
            default => new Passthrough
        };
        $this->castToInt = $config->keyType === KeyType::INT;
        parent::__construct($config, $serializer);

        self::$mutex ??= new LocalKeyedMutex;
        \assert($config->settings instanceof RedisSettings);
        $dbKey = $config->settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                self::$connections[$dbKey] = new RedisClient(new ReconnectingRedisLink(createRedisConnector($config->settings->config)));
                self::$connections[$dbKey]->ping();
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }
        $this->db = self::$connections[$dbKey];
    }

    public function set(string|int $key, mixed $value): void
    {
        /** @var string */
        $value = $this->serializer->serialize($value);
        $this->db->set($this->config->table.':'.(string) $key, $value);
    }

    public function get(string|int $key): mixed
    {
        $key = (string) $key;

        $value = $this->db->get($this->config->table.':'.$key);

        if ($value !== null) {
            /** @var TValue */
            $value = $this->serializer->deserialize($value);
        }

        return $value;
    }

    public function unset(string|int $key): void
    {
        $this->db->delete($this->config->table.':'.(string) $key);
    }

    /**
     * Get iterator.
     *
     * @return \Traversable<array-key, mixed>
     */
    public function getIterator(): \Traversable
    {
        $request = $this->db->scan($this->config->table.':*');

        $len = \strlen($this->config->table)+1;
        foreach ($request as $key) {
            $sub = \substr($key, $len);
            if ($this->castToInt) {
                $sub = (int) $sub;
            }
            yield $sub => $this->serializer->deserialize($this->db->get($key));
        }
    }

    /**
     * Count elements.
     *
     * @api
     *
     * @link https://php.net/manual/en/arrayiterator.count.php
     * @return int The number of elements or public properties in the associated
     *             array or object, respectively.
     */
    public function count(): int
    {
        return \iterator_count($this->db->scan($this->config->table.':*'));
    }

    /**
     * Clear all elements.
     */
    public function clear(): void
    {
        $request = $this->db->scan($this->config->table.':*');

        $keys = [];
        foreach ($request as $key) {
            $keys[] = $key;
            if (\count($keys) === 10) {
                // @codeCoverageIgnoreStart
                $this->db->delete(...$keys);
                $keys = [];
                // @codeCoverageIgnoreEnd
            }
        }
        if ($keys) {
            $this->db->delete(...$keys);
        }
    }
}
