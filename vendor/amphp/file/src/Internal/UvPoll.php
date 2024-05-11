<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Revolt\EventLoop\Driver\UvDriver as UvLoopDriver;

/** @internal */
final class UvPoll
{
    private readonly string $watcher;

    private int $requests = 0;

    public function __construct(private readonly UvLoopDriver $driver)
    {
        // Create dummy watcher to keep loop running while polling.

        /** @psalm-suppress InternalMethod */
        $this->watcher = $this->driver->repeat(600, static fn () => null);

        /** @psalm-suppress InternalMethod */
        $this->driver->disable($this->watcher);
    }

    public function __destruct()
    {
        $this->driver->cancel($this->watcher);
    }

    public function listen(): void
    {
        if ($this->requests++ === 0) {
            /** @psalm-suppress InternalMethod */
            $this->driver->enable($this->watcher);
        }
    }

    public function done(): void
    {
        if (--$this->requests === 0) {
            /** @psalm-suppress InternalMethod */
            $this->driver->disable($this->watcher);
        }

        \assert($this->requests >= 0);
    }
}
