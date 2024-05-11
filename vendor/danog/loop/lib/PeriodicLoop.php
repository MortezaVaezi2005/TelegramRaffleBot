<?php declare(strict_types=1);
/**
 * Periodic loop.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop;

/**
 * Periodic loop.
 *
 * @api
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
class PeriodicLoop extends GenericLoop
{
    /**
     * Constructor.
     *
     * Runs a callback at a periodic interval.
     *
     * The callable will be passed the instance of the current loop.
     *
     * The loop can be stopped from the outside by calling stop()
     * and from the inside by returning `true`.
     *
     * @param callable(static):bool $callback Callable to run
     * @param string   $name     Loop name
     * @param ?float   $interval Loop interval; if null, pauses indefinitely or until `resume()` is called.
     */
    public function __construct(callable $callback, string $name, ?float $interval)
    {
        /** @psalm-suppress InvalidArgument */
        parent::__construct(
            /** @param static $loop */
            static function (self $loop) use ($callback, $interval): ?float {
                if ($callback($loop) === true) {
                    return GenericLoop::STOP;
                }
                return $interval;
            },
            $name
        );
    }
}
