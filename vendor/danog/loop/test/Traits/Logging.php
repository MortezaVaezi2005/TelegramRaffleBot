<?php declare(strict_types=1);
/**
 * Loop test trait.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Traits;

trait Logging
{
    /**
     * Check whether the loop started.
     */
    private int $startCounter = 0;
    /**
     * Check whether the loop ended.
     */
    private int $endCounter = 0;

    /**
     * Signal that loop started.
     *
     */
    final protected function startedLoop(): void
    {
        parent::startedLoop();
        $this->startCounter++;
    }
    /**
     * Signal that loop ended.
     *
     */
    final protected function exitedLoop(): void
    {
        parent::exitedLoop();
        $this->endCounter++;
    }

    /**
     * Get start counter.
     */
    public function startCounter(): int
    {
        return $this->startCounter;
    }
    /**
     * Get end counter.
     */
    public function endCounter(): int
    {
        return $this->endCounter;
    }
}
