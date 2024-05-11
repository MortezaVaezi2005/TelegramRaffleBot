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

use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\DriverSettings;

use function Amp\async;
use function Amp\Future\await;

/**
 * Base class for driver-based arrays.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @psalm-consistent-constructor
 *
 * @extends DbArray<TKey, TValue>
 *
 * @api
 */
abstract class DriverArray extends DbArray
{
    private bool $inited = false;
    /**
     * @param Serializer<TValue> $serializer
     */
    protected function __construct(
        protected readonly DbArrayBuilder $config,
        protected readonly Serializer $serializer
    ) {
        $this->inited = true;
    }

    /**
     * @template TTKey as array-key
     * @template TTValue
     * @param DbArray<TTKey, TTValue> $previous
     * @return DbArray<TTKey, TTValue>
     */
    public static function getInstance(DbArrayBuilder $config, DbArray|null $previous): DbArray
    {
        $migrate = true;
        /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
        if ($previous !== null
            && $previous::class === static::class
            && $previous->config == $config
        ) {
            if ($previous->inited) {
                return $previous;
            }
            $migrate = false;
        }
        \assert($config->settings instanceof DriverSettings);

        /** @var DbArray<TTKey, TTValue> */
        $instance = new static($config, $config->settings->serializer);

        if ($previous === null || !$migrate) {
            return $instance;
        }

        /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
        if ($previous instanceof SqlArray
            && $instance instanceof SqlArray
            && $previous::class === $instance::class
        ) {
            $instance->importFromTable($previous->config->table);
        } else {
            $promises = [];
            foreach ($previous->getIterator() as $key => $value) {
                $promises []= async($previous->unset(...), $key)
                    ->map(static fn () => $instance->set($key, $value));
                if (\count($promises) % 500 === 0) {
                    // @codeCoverageIgnoreStart
                    await($promises);
                    $promises = [];
                    // @codeCoverageIgnoreEnd
                }
            }
            if ($promises) {
                await($promises);
            }
        }

        return $instance;
    }

    /**
     * Sleep function.
     */
    public function __sleep(): array
    {
        return ['config'];
    }
}
