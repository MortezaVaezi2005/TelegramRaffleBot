<?php declare(strict_types=1);

/**
 * Basic loop test interface.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Interfaces;

/**
 * Basic loop test interface.
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
interface LoggingPauseInterface extends LoggingInterface
{
    /**
     * Get number of times loop was paused.
     */
    public function getPauseCount(): int;
    /**
     * Get last pause.
     */
    public function getLastPause(): float;
}
