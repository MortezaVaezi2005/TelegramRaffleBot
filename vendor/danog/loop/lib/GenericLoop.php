<?php declare(strict_types=1);

/**
 * Generic loop.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop;

/**
 * Generic loop, runs single callable.
 *
 * @api
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
class GenericLoop extends Loop
{
    /**
     * Callable.
     *
     * @var callable(static):?float
     */
    private $callable;
    /**
     * Constructor.
     *
     * The return value of the callable can be:
     * * A number - the loop will be paused for the specified number of seconds
     * * GenericLoop::STOP - The loop will stop
     * * GenericLoop::PAUSE - The loop will pause forever (or until loop is `resumed()`
     *                        from outside the loop)
     * * GenericLoop::CONTINUE - Return this if you want to rerun the loop immediately
     *
     * If the callable does not return anything,
     * the loop will behave is if GenericLoop::PAUSE was returned.
     *
     * The callable will be passed the instance of the current loop.
     *
     * The loop can be stopped from the outside by using stop().
     *
     * @param callable(static):?float $callable Callable to run
     * @param string   $name     Loop name
     */
    public function __construct(callable $callable, private string $name)
    {
        $this->callable = $callable;
    }

    protected function loop(): ?float
    {
        return ($this->callable)($this);
    }
    public function __toString(): string
    {
        return $this->name;
    }
}
