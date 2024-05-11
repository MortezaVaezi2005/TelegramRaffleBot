# Loop

![Build status](https://github.com/danog/loop/workflows/build/badge.svg)
[![codecov](https://codecov.io/gh/danog/loop/branch/master/graph/badge.svg)](https://codecov.io/gh/danog/loop)
[![Psalm coverage](https://shepherd.dev/github/danog/loop/coverage.svg)](https://shepherd.dev/github/danog/loop)
[![Psalm level 1](https://shepherd.dev/github/danog/loop/level.svg)](https://shepherd.dev/github/danog/loop)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

`danog/loop` provides a set of powerful async loop APIs based on [amphp](https://amphp.org) for executing operations periodically or on demand, in background loops a-la threads.  

## Installation

```bash
composer require danog/loop
```

## API

* Basic
  * [GenericLoop](#genericloop)
  * [PeriodicLoop](#periodicloop)
* Advanced
  * [Loop](#loop)

### Loop

[Class](https://github.com/danog/loop/blob/master/lib/Loop.php) - [Example](https://github.com/danog/loop/blob/master/examples/Loop.php)

A loop capable of running in background (asynchronously) the code contained in the `loop` function.  
Implements pause and resume functionality, and can be stopped from the outside or from the inside.  

API:  

```php
namespace danog\Loop;

abstract class Loop
{
    /**
     * Stop the loop.
     */
    public const STOP;
    /**
     * Pause the loop.
     */
    public const PAUSE;
    /**
     * Rerun the loop.
     */
    public const CONTINUE;
    
    /**
     * Loop body.
     * 
     * The return value can be:
     * A number - the loop will be paused for the specified number of seconds
     * Loop::STOP - The loop will stop
     * Loop::PAUSE - The loop will pause forever (or until loop is `resume()`'d
     *                        from outside the loop)
     * Loop::CONTINUE - Return this if you want to rerun the loop immediately
     *
     * The loop can be stopped from the outside by using stop().
     * @return float|Loop::STOP|Loop::PAUSE|Loop::CONTINUE
     */
    abstract protected function loop(): ?float;

    /**
     * Loop name, useful for logging.
     */
    abstract public function __toString(): string;
    
    /**
     * Start the loop.
     *
     * Returns false if the loop is already running.
     */
    public function start(): bool;
    /**
     * Resume the loop.
     *
     * If resume is called multiple times, and the event loop hasn't resumed the loop yet,
     * the loop will be resumed only once, not N times for every call.
     *
     * @param bool $postpone If true, multiple resumes will postpone the resuming to the end of the callback queue instead of leaving its position unchanged.
     *
     * @return bool Returns false if the loop is not paused.
     */
    public function resume(bool $postpone = false): bool;
    /**
     * Stops loop.
     *
     * Returns false if the loop is not running.
     */
    public function stop(): bool;

    /**
     * Check whether loop is running.
     */
    public function isRunning(): bool;
    /**
     * Check whether loop is paused.
     */
    public function isPaused(): bool;

    /**
     * Report pause, can be overriden for logging.
     *
     * @param float $timeout Pause duration, 0 = forever
     */
    protected function reportPause(float $timeout): void;
    
    /**
     * Signal that loop was started.
     */
    protected function startedLoop(): void;
    /**
     * Signal that loop has exited.
     */
    protected function exitedLoop(): void;
}
```

### GenericLoop

[Class](https://github.com/danog/loop/blob/master/lib/GenericLoop.php) - [Example](https://github.com/danog/loop/blob/master/examples/GenericLoop.php)

If you want a simpler way to use the `Loop`, you can use the GenericLoop.  

```php
namespace danog\Loop;

class GenericLoop extends Loop
{
    /**
     * Constructor.
     *
     * The return value of the callable can be:
     * * A number - the loop will be paused for the specified number of seconds
     * * GenericLoop::STOP - The loop will stop
     * * GenericLoop::PAUSE - The loop will pause forever (or until loop is `resume()`'d
     *                        from outside the loop)
     * * GenericLoop::CONTINUE - Return this if you want to rerun the loop immediately
     *
     * If the callable does not return anything,
     * the loop will behave is if GenericLoop::PAUSE was returned.
     *
     * The loop can be stopped from the outside by using stop().
     *
     * @param callable(static):?float $callable Callable to run
     * @param string   $name     Loop name
     */
    public function __construct(callable $callable, private string $name);
    /**
     * Get loop name, provided to constructor.
     */
    public function __toString(): string;
}
```

### PeriodicLoop

[Class](https://github.com/danog/loop/blob/master/lib/PeriodicLoop.php) - [Example](https://github.com/danog/loop/blob/master/examples/PeriodicLoop.php)

If you simply want to execute an action every N seconds, [PeriodicLoop](https://github.com/danog/MadelineProto/blob/master/src/danog/MadelineProto/Loop/Generic/PeriodicLoop.php) is the way to go.  

```php
namespace danog\Loop;

class PeriodicLoop extends GenericLoop
{
    /**
     * Constructor.
     *
     * Runs a callback at a periodic interval.
     *
     * The loop can be stopped from the outside by calling stop()
     * and from the inside by returning `true`.
     *
     * @param callable(static):bool $callback Callable to run
     * @param string   $name     Loop name
     * @param ?float   $interval Loop interval; if null, pauses indefinitely or until `resume()` is called.
     */
    public function __construct(callable $callback, string $name, ?float $interval)
    /**
     * Get name of the loop, passed to the constructor.
     *
     * @return string
     */
    public function __toString(): string;
}
```

