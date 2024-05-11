<?php declare(strict_types=1);
/**
 * Loop test trait.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test\Traits;

use function Amp\delay;

trait LoggingPause
{
    use Logging;
    /**
     * Number of times loop was paused.
     */
    private int $pauseCount = 0;
    /**
     * Last pause delay.
     */
    private float $lastPause = 0;
    /**
     * Get number of times loop was paused.
     */
    public function getPauseCount(): int
    {
        return $this->pauseCount;
    }

    /**
     * Get last pause.
     */
    public function getLastPause(): float
    {
        return $this->lastPause;
    }
    /**
     * Report pause, can be overriden for logging.
     *
     * @param integer $timeout Pause duration, 0 = forever
     *
     */
    protected function reportPause(float $timeout): void
    {
        parent::reportPause($timeout);
        $this->pauseCount++;
        $this->lastPause = $timeout;
    }
}
