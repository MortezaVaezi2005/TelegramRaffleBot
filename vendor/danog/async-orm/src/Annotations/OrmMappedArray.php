<?php declare(strict_types=1);

/**
 * This file is part of AsyncOrm.
 * AsyncOrm is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General private License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * AsyncOrm is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General private License for more details.
 * You should have received a copy of the GNU General private License along with AsyncOrm.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://docs.AsyncOrm.xyz AsyncOrm documentation
 */

namespace danog\AsyncOrm\Annotations;

use Attribute;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\ValueType;

/**
 * Attribute use to autoconfigure ORM properties.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OrmMappedArray
{
    public function __construct(
        /**
         * Key type.
         */
        public readonly KeyType $keyType,
        /**
         * Value type.
         */
        public readonly ValueType $valueType,
        /**
         * TTL of the cache, if null defaults to the value specified in the settings.
         *
         * If zero disables caching.
         *
         * @var int<0, max>|null
         */
        public readonly ?int $cacheTtl = null,
        /**
         * Optimize table if more than this many megabytes are wasted, if null defaults to the value specified in the settings.
         *
         * @var int<1, max>|null
         */
        public readonly ?int $optimizeIfWastedMb = null,
        /**
         * Table name postfix, if null defaults to the property name.
         */
        public readonly ?string $tablePostfix = null
    ) {
    }
}
