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

namespace danog\AsyncOrm;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\MysqlSettings;
use ReflectionClass;

use function Amp\async;
use function Amp\Future\await;

/**
 * Trait that provides autoconfiguration of OrmMappedArray properties.
 *
 * @api
 */
trait DbAutoProperties
{
    /** @var list<CachedArray> */
    private array $properties = [];

    /** @return list<\ReflectionProperty> */
    private function getDbAutoProperties(): array
    {
        $res = [];
        foreach ((new ReflectionClass(static::class))->getProperties() as $property) {
            $attr = $property->getAttributes(OrmMappedArray::class);
            if (!$attr) {
                continue;
            }
            $res []= $property;
        }
        return $res;
    }

    /**
     * Initialize database properties.
     */
    public function initDbProperties(Settings $settings, string $tablePrefix): void
    {
        $this->properties = [];
        $promises = [];
        foreach ($this->getDbAutoProperties() as $property) {
            $attr = $property->getAttributes(OrmMappedArray::class);
            \assert(\count($attr) !== 0);
            $attr = $attr[0]->newInstance();

            if ($settings instanceof DriverSettings) {
                $ttl = $attr->cacheTtl ?? $settings->cacheTtl;
                if ($ttl !== $settings->cacheTtl) {
                    $settings = new $settings(...\array_merge(
                        (array) $settings,
                        ['cacheTtl' => $ttl]
                    ));
                }
                if ($settings instanceof MysqlSettings) {
                    $optimize = $attr->optimizeIfWastedMb ?? $settings->optimizeIfWastedMb;

                    if ($optimize !== $settings->optimizeIfWastedMb) {
                        $settings = new $settings(...\array_merge(
                            (array) $settings,
                            ['optimizeIfWastedMb' => $optimize]
                        ));
                    }
                }
            }

            $config = new DbArrayBuilder(
                $tablePrefix.($attr->tablePostfix ?? $property->getName()),
                $settings,
                $attr->keyType,
                $attr->valueType,
            );

            $promises[] = async(function () use ($config, $property) {
                $v = $config->build(
                    $property->isInitialized($this)
                        ? $property->getValue($this)
                        : null
                );
                $property->setValue($this, $v);
                if ($v instanceof CachedArray) {
                    $this->properties []= $v;
                }
            });
        }
        await($promises);
    }

    /**
     * Save all properties.
     */
    public function saveDbProperties(): void
    {
        $futures = [];
        foreach ($this->properties as $prop) {
            $futures []= async($prop->flushCache(...));
        }
        await($futures);
    }
}
