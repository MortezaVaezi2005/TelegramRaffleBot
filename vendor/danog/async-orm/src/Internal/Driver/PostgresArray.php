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

use Amp\Postgres\PostgresConnectionPool;
use Amp\Sync\LocalKeyedMutex;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\Internal\Serializer\ByteaSerializer;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\PostgresSettings;
use danog\AsyncOrm\ValueType;
use Revolt\EventLoop;

/**
 * Postgres database backend.
 *
 * @internal
 * @template TKey as array-key
 * @template TValue
 * @extends SqlArray<TKey, TValue>
 */
class PostgresArray extends SqlArray
{
    /** @var array<PostgresConnectionPool> */
    private static array $connections = [];

    private static ?LocalKeyedMutex $mutex = null;
    /**
     * @psalm-suppress MethodSignatureMismatch
     * @param Serializer<TValue> $serializer
     */
    public function __construct(DbArrayBuilder $config, Serializer $serializer)
    {
        self::$mutex ??= new LocalKeyedMutex;
        $settings = $config->settings;
        \assert($settings instanceof PostgresSettings);

        $dbKey = $settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                $db = $settings->config->getDatabase();
                $user = $settings->config->getUser();
                $connection =  new PostgresConnectionPool($settings->config->withDatabase(null));

                $result = $connection->query("SELECT * FROM pg_database WHERE datname = '{$db}'");

                // Replace with getRowCount once it gets fixed
                if (!\iterator_count($result)) {
                    $connection->query("
                        CREATE DATABASE {$db}
                        OWNER {$user}
                        ENCODING utf8
                    ");
                }
                $connection->close();

                self::$connections[$dbKey] = new PostgresConnectionPool($settings->config, $settings->maxConnections, $settings->idleTimeout);
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }

        $connection = self::$connections[$dbKey];

        $keyType = match ($config->keyType) {
            KeyType::STRING_OR_INT => "VARCHAR(255)",
            KeyType::STRING => "VARCHAR(255)",
            KeyType::INT => "BIGINT",
        };
        $valueType = match ($config->valueType) {
            ValueType::INT => "BIGINT",
            ValueType::STRING => "VARCHAR(255)",
            ValueType::FLOAT => "FLOAT(53)",
            ValueType::BOOL => "BOOLEAN",
            ValueType::SCALAR, ValueType::OBJECT => "BYTEA",
        };
        /** @var Serializer<TValue> */
        $serializer = match ($config->valueType) {
            ValueType::SCALAR, ValueType::OBJECT => new ByteaSerializer($serializer),
            default => new Passthrough
        };

        /** @psalm-suppress InvalidArgument */
        parent::__construct(
            $config,
            $serializer,
            $connection,
            "SELECT value FROM \"bytea_{$config->table}\" WHERE key = :index",
            "
                INSERT INTO \"bytea_{$config->table}\"
                (key,value)
                VALUES (:index, :value)
                ON CONFLICT (key) DO UPDATE SET value = :value
            ",
            "
                DELETE FROM \"bytea_{$config->table}\"
                WHERE key = :index
            ",
            "SELECT count(key) as count FROM \"bytea_{$config->table}\"",
            "SELECT key, value FROM \"bytea_{$config->table}\"",
            "DELETE FROM \"bytea_{$config->table}\""
        );

        $connection->query("
            CREATE TABLE IF NOT EXISTS \"bytea_{$config->table}\"
            (
                \"key\" $keyType PRIMARY KEY NOT NULL,
                \"value\" $valueType NOT NULL
            );            
        ");

        $result = $connection->query("SELECT * FROM information_schema.columns WHERE table_name='bytea_{$config->table}'");
        while ($column = $result->fetchRow()) {
            ['column_name' => $key, 'data_type' => $type, 'is_nullable' => $null] = $column;
            \assert(\is_string($key));
            \assert(\is_string($type));
            $type = \strtoupper($type);
            if (\str_starts_with($type, 'BIGINT')) {
                $type = 'BIGINT';
            }
            if ($key === 'key') {
                $expected = $keyType;
            } elseif ($key === 'value') {
                $expected = $valueType;
            } else {
                // @codeCoverageIgnoreStart
                $connection->query("ALTER TABLE \"bytea_{$config->table}\" DROP \"$key\"");
                continue;
                // @codeCoverageIgnoreEnd
            }
            if ($expected !== $type) {
                if ($expected === 'BIGINT') {
                    $expected .= " USING $key::bigint";
                }
                $connection->query("ALTER TABLE \"bytea_{$config->table}\" ALTER COLUMN \"$key\" TYPE $expected");
            }
            if ($null !== 'NO') {
                // @codeCoverageIgnoreStart
                $connection->query("ALTER TABLE \"bytea_{$config->table}\" ALTER COLUMN \"$key\" SET NOT NULL");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    protected function importFromTable(string $fromTable): void
    {
        $this->db->query(/** @lang PostgreSQL */ "
            DROP TABLE \"bytea_{$this->config->table}\";
        ");
        $this->db->query(/** @lang PostgreSQL */ "
            ALTER TABLE \"bytea_$fromTable\" RENAME TO \"bytea_{$this->config->table}\";
        ");
    }
}
