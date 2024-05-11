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

use Amp\Redis\RedisConfig;
use danog\AsyncOrm\Internal\Driver\RedisArray;
use danog\AsyncOrm\Serializer;

/**
 * Redis backend settings.
 */
final readonly class RedisSettings extends DriverSettings
{
    /**
     * @api
     *
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     */
    public function __construct(
        public readonly RedisConfig $config,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
        parent::__construct($serializer, $cacheTtl);
    }
    /** @internal */
    public function getDbIdentifier(): string
    {
        $host = $this->config->getConnectUri();
        return "$host\0".$this->config->getDatabase();
    }
    public function getDriverClass(): string
    {
        return RedisArray::class;
    }
}
