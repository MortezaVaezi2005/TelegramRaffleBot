<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\weakClosure;

final class PeriodicHeartbeatQueue implements WebsocketHeartbeatQueue
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var array<int, \WeakReference<WebsocketClient>> */
    private array $clients = [];

    private readonly string $watcher;

    /** @var array<int, float> Least-recently-used cache of next ping (heartbeat) times. */
    private array $heartbeatTimeouts = [];

    /** @var float Cached current time to avoid syscall on each update. */
    private float $now;

    /**
     * @param positive-int $queuedPingLimit
     * @param positive-int $heartbeatPeriod
     */
    public function __construct(
        int $queuedPingLimit = 3,
        private readonly int $heartbeatPeriod = 10,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($queuedPingLimit <= 0) {
            throw new \ValueError('Queued ping limit must be greater than 0');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->heartbeatPeriod <= 0) {
            throw new \ValueError('Heartbeat period must be greater than 0');
        }

        $this->now = \microtime(true);

        $this->watcher = EventLoop::repeat(1, weakClosure(function () use ($queuedPingLimit): void {
            $this->now = \microtime(true);

            foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
                if ($expiryTime >= $this->now) {
                    break;
                }

                /** @var WebsocketClient|null $client */
                $client = ($this->clients[$clientId] ?? null)?->get();
                if (!$client) {
                    unset($this->heartbeatTimeouts[$clientId]);
                    continue;
                }

                if ($client->getCount(WebsocketCount::UnansweredPings) > $queuedPingLimit) {
                    $this->remove($clientId);
                    async($client->close(...), WebsocketCloseCode::POLICY_VIOLATION, 'Exceeded unanswered PING limit')->ignore();
                    continue;
                }

                $this->update($clientId);

                async($client->ping(...))->ignore();
            }
        }));

        EventLoop::unreference($this->watcher);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function insert(WebsocketClient $client): void
    {
        $clientId = $client->getId();
        $this->clients[$clientId] = \WeakReference::create($client);
        $this->update($clientId);
    }

    public function update(int $clientId): void
    {
        \assert(isset($this->clients[$clientId]));
        unset($this->heartbeatTimeouts[$clientId]); // Unset to force ordering to end of list.
        $this->heartbeatTimeouts[$clientId] = $this->now + $this->heartbeatPeriod;
    }

    public function remove(int $clientId): void
    {
        unset($this->clients[$clientId], $this->heartbeatTimeouts[$clientId]);
    }
}
