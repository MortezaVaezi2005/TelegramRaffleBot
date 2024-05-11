<?php declare(strict_types=1);

/**
 * Generic loop.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop;

use Amp\DeferredFuture;
use AssertionError;
use Revolt\EventLoop;
use Stringable;

/**
 * Generic loop, runs single callable.
 *
 * @api
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
abstract class Loop implements Stringable
{
    /**
     * Stop the loop.
     */
    public const STOP = -1.0;
    /**
     * Pause the loop.
     */
    public const PAUSE = null;
    /**
     * Rerun the loop.
     */
    public const CONTINUE = 0.0;
    /**
     * Whether the loop is running.
     */
    private bool $running = false;
    /**
     * Resume timer ID.
     */
    private ?string $resumeTimer = null;
    /**
     * Resume deferred ID.
     */
    private ?string $resumeImmediate = null;
    /**
     * Shutdown deferred.
     */
    private ?DeferredFuture $shutdownDeferred = null;

    /**
     * Report pause, can be overriden for logging.
     *
     * @psalm-suppress PossiblyUnusedParam
     *
     * @param float $timeout Pause duration, 0 = forever
     */
    protected function reportPause(float $timeout): void
    {
    }

    /**
     * Start the loop.
     *
     * Returns false if the loop is already running.
     */
    public function start(): bool
    {
        while ($this->shutdownDeferred !== null) {
            $this->shutdownDeferred->getFuture()->await();
        }
        if ($this->running) {
            return false;
        }
        $this->running = true;
        if (!$this->resume()) {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Could not resume!");
            // @codeCoverageIgnoreEnd
        }
        $this->startedLoop();
        return true;
    }
    /**
     * Stops loop.
     *
     * Returns false if the loop is not running.
     */
    public function stop(): bool
    {
        if (!$this->running) {
            return false;
        }
        $this->running = false;
        if ($this->resumeTimer) {
            $storedWatcherId = $this->resumeTimer;
            EventLoop::cancel($storedWatcherId);
            $this->resumeTimer = null;
        }
        if ($this->resumeImmediate) {
            $storedWatcherId = $this->resumeImmediate;
            EventLoop::cancel($storedWatcherId);
            $this->resumeImmediate = null;
        }
        if ($this->paused) {
            $this->exitedLoop();
        } else {
            if ($this->shutdownDeferred !== null) {
                // @codeCoverageIgnoreStart
                throw new AssertionError("Shutdown deferred is not null!");
                // @codeCoverageIgnoreEnd
            }
            $this->shutdownDeferred = new DeferredFuture;
        }
        return true;
    }
    abstract protected function loop(): ?float;

    private bool $paused = true;
    private function loopInternal(): void
    {
        if (!$this->running) {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Already running!");
            // @codeCoverageIgnoreEnd
        }
        if (!$this->paused) {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Already paused!");
            // @codeCoverageIgnoreEnd
        }
        $this->paused = false;
        try {
            $timeout = $this->loop();
        } catch (\Throwable $e) {
            $this->exitedLoopInternal();
            throw $e;
        }
        /** @var bool $this->running */
        if (!$this->running) {
            $this->exitedLoopInternal();
            return;
        }
        if ($timeout === self::STOP) {
            $this->exitedLoopInternal();
            return;
        }

        $this->paused = true;
        if ($timeout === self::PAUSE) {
            $this->reportPause(0.0);
        } else {
            if (!$this->resumeImmediate) {
                if ($this->resumeTimer !== null) {
                    // @codeCoverageIgnoreStart
                    throw new AssertionError("Already have a resume timer!");
                    // @codeCoverageIgnoreEnd
                }
                $this->resumeTimer = EventLoop::delay($timeout, function (): void {
                    $this->resumeTimer = null;
                    $this->loopInternal();
                });
            }
            if ($timeout !== self::CONTINUE) {
                $this->reportPause($timeout);
            }
        }
    }

    private function exitedLoopInternal(): void
    {
        $this->running = false;
        $this->paused = true;
        if ($this->resumeTimer !== null) {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Already have a resume timer!");
            // @codeCoverageIgnoreEnd
        }
        if ($this->resumeImmediate !== null) {
            // @codeCoverageIgnoreStart
            throw new AssertionError("Already have a resume immediate timer!");
            // @codeCoverageIgnoreEnd
        }
        $this->exitedLoop();
        if ($this->shutdownDeferred !== null) {
            $d = $this->shutdownDeferred;
            $this->shutdownDeferred = null;
            EventLoop::queue($d->complete(...));
        }
    }
    /**
     * Signal that loop was started.
     */
    protected function startedLoop(): void
    {
    }
    /**
     * Signal that loop has exited.
     */
    protected function exitedLoop(): void
    {
    }
    /**
     * Check whether loop is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
    /**
     * Check whether loop is paused (different from isRunning, a loop may be running but paused).
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

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
    public function resume(bool $postpone = false): bool
    {
        if ($this->running && $this->paused) {
            if ($this->resumeImmediate) {
                if (!$postpone) {
                    return true;
                }
                $resumeImmediate = $this->resumeImmediate;
                $this->resumeImmediate = null;
                EventLoop::cancel($resumeImmediate);
            }
            if ($this->resumeTimer) {
                $timer = $this->resumeTimer;
                $this->resumeTimer = null;
                EventLoop::cancel($timer);
            }
            $this->resumeImmediate = EventLoop::defer(function (): void {
                $this->resumeImmediate = null;
                $this->loopInternal();
            });
            return true;
        }
        return false;
    }
}
