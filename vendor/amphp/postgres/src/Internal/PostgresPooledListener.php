<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresNotification;
use Revolt\EventLoop;

/**
 * @internal
 * @implements \IteratorAggregate<int, PostgresNotification>
 */
final class PostgresPooledListener implements PostgresListener, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly PostgresListener $listener;

    /** @var null|\Closure():void  */
    private ?\Closure $release;

    /**
     * @param \Closure():void $release
     */
    public function __construct(PostgresListener $listener, \Closure $release)
    {
        $this->listener = $listener;
        $this->release = $release;

        if (!$this->listener->isListening()) {
            ($this->release)();
            $this->release = null;
        }
    }

    public function __destruct()
    {
        if ($this->listener->isListening() && $this->release) {
            EventLoop::queue(self::dispose(...), $this->listener, $this->release);
            $this->release = null;
        }
    }

    /**
     * @param \Closure():void $release
     */
    private static function dispose(PostgresListener $listener, \Closure $release): void
    {
        try {
            $listener->unlisten();
        } finally {
            EventLoop::queue($release);
        }
    }

    public function getIterator(): \Traversable
    {
        // Using a Generator to keep a reference to $this.
        yield from $this->listener;
    }

    public function getChannel(): string
    {
        return $this->listener->getChannel();
    }

    public function isListening(): bool
    {
        return $this->listener->isListening();
    }

    public function unlisten(): void
    {
        if (!$this->release) {
            return;
        }

        $release = $this->release;
        $this->release = null;

        try {
            $this->listener->unlisten();
        } finally {
            EventLoop::queue($release);
        }
    }
}
