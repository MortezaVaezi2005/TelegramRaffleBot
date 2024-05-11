<?php declare(strict_types=1);

/**
 * Resumable loop test interface.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Interfaces;

/**
 * Resumable loop test interface.
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
interface IntervalInterface
{
    /**
     * Set sleep interval.
     *
     * @param ?int $interval Interval
     *
     */
    public function setInterval(?int $interval): void;
}
