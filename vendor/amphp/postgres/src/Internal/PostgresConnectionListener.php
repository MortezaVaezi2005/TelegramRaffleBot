<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresNotification;
use function Amp\async;

/**
 * @internal
 * @implements \IteratorAggregate<int, PostgresNotification>
 */
final class PostgresConnectionListener implements PostgresListener, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure(non-empty-string):void */
    private ?\Closure $unlisten;

    /**
     * @param \Traversable<int, PostgresNotification> $source Traversable of notifications on the channel.
     * @param non-empty-string $channel Channel name.
     * @param \Closure(non-empty-string):void $unlisten Function invoked to stop listening on the channel.
     */
    public function __construct(
        private readonly \Traversable $source,
        private readonly string $channel,
        \Closure $unlisten,
    ) {
        $this->unlisten = $unlisten;
    }

    public function __destruct()
    {
        if ($this->unlisten) {
            async($this->unlisten, $this->channel);
            $this->unlisten = null;
        }
    }

    public function getIterator(): \Traversable
    {
        // Using a Generator to keep a reference to $this.
        yield from $this->source;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function isListening(): bool
    {
        return $this->unlisten !== null;
    }

    /**
     * Unlistens from the channel. No more values will be emitted from this listener.
     *
     * @throws \Error If this method was previously invoked.
     */
    public function unlisten(): void
    {
        if (!$this->unlisten) {
            return;
        }

        $unlisten = $this->unlisten;
        $this->unlisten = null;

        $unlisten($this->channel);
    }
}
