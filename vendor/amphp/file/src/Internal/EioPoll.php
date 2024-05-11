<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Revolt\EventLoop\Driver as EventLoopDriver;

/** @internal */
final class EioPoll
{
    /** @var resource */
    private static $stream;

    private readonly string $watcher;

    private int $requests = 0;

    public function __construct(private readonly EventLoopDriver $driver)
    {
        if (!self::$stream) {
            if (\function_exists('eio_init')) {
                eio_init();
            }
            self::$stream = \eio_get_event_stream();
        }

        $this->watcher = $this->driver->onReadable(self::$stream, static function (): void {
            while (\eio_npending()) {
                \eio_poll();
            }
        });

        $this->driver->disable($this->watcher);
    }

    public function __destruct()
    {
        $this->driver->cancel($this->watcher);

        // Ensure there are no active operations anymore. This is a safe-guard as some operations might not be
        // finished on loop exit due to not being awaited. This also ensures a clean shutdown for these if a PHP
        // execution context still exists.
        \eio_event_loop();
    }

    public function listen(): void
    {
        if ($this->requests++ === 0) {
            $this->driver->enable($this->watcher);
        }
    }

    public function done(): void
    {
        if (--$this->requests === 0) {
            $this->driver->disable($this->watcher);
        }

        \assert($this->requests >= 0);
    }
}
