<?php declare(strict_types=1);
/**
 * Exception test trait.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Traits;

trait BasicException
{
    use Basic;
    /**
     * Loop implementation.
     *
     */
    public function loop(): ?float
    {
        $this->inited = true;
        throw new \RuntimeException('Threw exception!');
        $this->ran = true;
    }
}
