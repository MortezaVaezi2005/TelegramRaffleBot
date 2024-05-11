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

namespace danog\AsyncOrm\Settings;

use Amp\Sql\SqlConfig;
use danog\AsyncOrm\Serializer;

/**
 * Generic SQL db backend settings.
 *
 * @template T as SqlConfig
 */
abstract readonly class SqlSettings extends DriverSettings
{
    final public const DEFAULT_SQL_MAX_CONNECTIONS = 100;
    final public const DEFAULT_SQL_IDLE_TIMEOUT = 60;
    /**
     * Maximum connection limit.
     *
     * @var positive-int
     */
    public int $maxConnections;
    /**
     * Idle timeout.
     *
     * @var positive-int
     */
    public int $idleTimeout;

    /**
     * @param ?Serializer $serializer to use for object and mixed type values.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     * @param int<1, max> $maxConnections Maximum connection limit
     * @param int<1, max> $idleTimeout Idle timeout
     */
    public function __construct(
        /** @var T */
        public readonly SqlConfig $config,
        ?Serializer $serializer,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        int $maxConnections = self::DEFAULT_SQL_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_SQL_IDLE_TIMEOUT,
    ) {
        parent::__construct($serializer, $cacheTtl);
        $this->maxConnections = $maxConnections;
        $this->idleTimeout = $idleTimeout;
    }
    /** @internal */
    public function getDbIdentifier(): string
    {
        $host = $this->config->getHost();
        $port = $this->config->getPort();
        return "$host:$port:".(string) $this->config->getDatabase();
    }
}
