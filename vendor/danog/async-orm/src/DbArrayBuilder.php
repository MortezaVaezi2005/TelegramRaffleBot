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
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm;

use AssertionError;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\ObjectArray;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\MemorySettings;

/**
 * Contains configuration needed to build a DbArray.
 *
 * @api
 */
final readonly class DbArrayBuilder
{
    public function __construct(
        /**
         * Table name.
         */
        public readonly string $table,
        /**
         * Settings.
         */
        public readonly Settings $settings,
        /**
         * Key type.
         */
        public readonly KeyType $keyType,
        /**
         * Value type.
         */
        public readonly ValueType $valueType,
    ) {
    }

    /**
     * Build database array.
     *
     * @template TKey as array-key
     * @template TValue
     *
     * @param DbArray<TKey, TValue>|null $previous
     * @return DbArray<TKey, TValue>
     */
    public function build(?DbArray $previous = null): DbArray
    {
        if ($this->valueType === ValueType::OBJECT) {
            if (!$this->settings instanceof DriverSettings) {
                throw new AssertionError("Objects can only be saved to a database backend!");
            }
            if ($this->settings->serializer instanceof Json) {
                throw new AssertionError("The JSON backend cannot be used when serializing objects!");
            }
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return ObjectArray::getInstance($this, $previous);
        }
        if ($this->settings instanceof MemorySettings
            || (
                $this->settings instanceof DriverSettings
                && $this->settings->cacheTtl === 0
            )
        ) {
            return $this->settings->getDriverClass()::getInstance($this, $previous);
        }

        return CachedArray::getInstance($this, $previous);
    }
}
