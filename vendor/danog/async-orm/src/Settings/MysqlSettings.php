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

use Amp\Mysql\MysqlConfig;
use danog\AsyncOrm\Internal\Driver\MysqlArray;
use danog\AsyncOrm\Serializer;

/**
 * MySQL backend settings.
 *
 * MariaDb 10.2+ or Mysql 5.6+ required.
 *
 * @extends SqlSettings<MysqlConfig>
 */
final readonly class MysqlSettings extends SqlSettings
{
    /**
     * @api
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds, if 0 disables caching.
     * @param int<1, max> $maxConnections Maximum connection limit
     * @param int<1, max> $idleTimeout Idle timeout
     */
    public function __construct(
        MysqlConfig $config,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        int $maxConnections = self::DEFAULT_SQL_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_SQL_IDLE_TIMEOUT,
        /**
         *
         * Whether to optimize MySQL tables automatically if more than the specified amount of megabytes is wasted by the MySQL engine.
         *
         * Be careful when tweaking this setting as it may lead to slowdowns on startup.
         *
         * If null disables optimization.
         *
         * @var int<1, max>|null $optimizeIfWastedMb
         */
        public ?int $optimizeIfWastedMb = null,
    ) {
        parent::__construct($config, $serializer, $cacheTtl, $maxConnections, $idleTimeout);
    }

    public function getDriverClass(): string
    {
        return MysqlArray::class;
    }
}
