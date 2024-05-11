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

use AssertionError;
use danog\AsyncOrm\Internal\Containers\ObjectContainer;

/** @api */
abstract class DbObject
{
    private ObjectContainer $mapper;
    private string|int|null $key = null;

    /**
     * Initialize database instance.
     *
     * @internal Do not invoke manually.
     */
    final public function initDb(ObjectContainer $mapper, string|int $key, DbArrayBuilder $config): void
    {
        $this->mapper = $mapper;
        $this->key = $key;
        $this->onLoaded($config);
    }

    /**
     * Save object to database.
     */
    public function save(): void
    {
        if ($this->key === null) {
            throw new AssertionError("Cannot save an uninitialized object!");
        }
        $this->onBeforeSave();
        $this->mapper->inner->set($this->key, $this);
        $this->onAfterSave();
    }

    // @codeCoverageIgnoreStart
    /**
     * Method invoked after loading the object.
     *
     * @psalm-suppress PossiblyUnusedParam
     */
    protected function onLoaded(DbArrayBuilder $config): void
    {

    }
    /**
     * Method invoked before saving the object.
     */
    protected function onBeforeSave(): void
    {

    }
    /**
     * Method invoked after saving the object.
     */
    protected function onAfterSave(): void
    {

    }
    // @codeCoverageIgnoreEnd
}
