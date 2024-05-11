<?php declare(strict_types=1);
/**
 * Loop test trait.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Traits;

use danog\Loop\Loop;
use danog\Loop\Test\LoopTest;

use function Amp\delay;

trait Basic
{
    use Logging;
    /**
     * Check whether the loop inited.
     *
     * @var bool
     */
    private $inited = false;
    /**
     * Check whether the loop ran.
     *
     * @var bool
     */
    private $ran = false;
    /**
     * Check whether the loop inited.
     */
    public function inited(): bool
    {
        return $this->inited;
    }
    /**
     * Check whether the loop ran.
     */
    public function ran(): bool
    {
        return $this->ran;
    }
    /**
     * Loop implementation.
     */
    public function loop(): ?float
    {
        $this->inited = true;
        delay(0.1);
        $this->ran = true;
        return Loop::STOP;
    }
    /**
     * Get loop name.
     *
     */
    public function __toString(): string
    {
        return LoopTest::LOOP_NAME;
    }
}
