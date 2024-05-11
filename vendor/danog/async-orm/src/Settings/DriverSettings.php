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

use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Serializer\Igbinary;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings;

/**
 * Base settings class for database backends.
 */
abstract readonly class DriverSettings implements Settings
{
    final public const DEFAULT_CACHE_TTL = 5*60;
    public readonly Serializer $serializer;
    /**
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     */
    public function __construct(
        ?Serializer $serializer = null,
        /**
         * @var int<0, max> $cacheTtl For how long to keep records in memory after last read.
         */
        public int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
        $this->serializer = $serializer ?? (\extension_loaded('igbinary') ? new Igbinary : new Native);
    }

    /**
     * Get the DB's unique ID.
     *
     * @internal
     */
    abstract public function getDbIdentifier(): string;
}
