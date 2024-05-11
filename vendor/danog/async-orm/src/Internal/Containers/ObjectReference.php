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
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Containers;

use Amp\Future;
use danog\AsyncOrm\DbObject;
use WeakReference;

/**
 * @template TObject as DbObject
 * @internal
 * @api
 */
final class ObjectReference
{
    /** @var WeakReference<TObject> */
    private readonly WeakReference $reference;
    public ?DbObject $obj;
    /** @param TObject $object */
    public function __construct(
        DbObject $object,
        public int $ttl,
        private ?Future $initFuture
    ) {
        $this->obj = $object;
        $this->reference = WeakReference::create($object);
    }

    /** @return ?TObject */
    public function get(): ?DbObject
    {
        $ref = $this->reference->get();
        if ($ref === null) {
            return $ref;
        }
        if ($this->initFuture !== null) {
            $this->initFuture->await();
            $this->initFuture = null;
        }
        return $ref;
    }
}
