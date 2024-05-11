<?php declare(strict_types=1);

namespace Amp\Redis;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Revolt\EventLoop;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class RedisSubscription implements \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure():void */
    private ?\Closure $unsubscribe;

    /**
     * @param \Closure():void $unsubscribe
     */
    public function __construct(
        private readonly ConcurrentIterator $iterator,
        \Closure $unsubscribe,
    ) {
        $this->unsubscribe = $unsubscribe;
    }

    public function __destruct()
    {
        $this->unsubscribe();
    }

    /**
     * Using a Generator to maintain a reference to $this.
     */
    public function getIterator(): \Traversable
    {
        yield from $this->iterator;
    }

    public function unsubscribe(): void
    {
        if ($this->unsubscribe) {
            EventLoop::queue($this->unsubscribe);
            $this->unsubscribe = null;
        }

        $this->iterator->dispose();
    }
}
