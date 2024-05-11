<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use function Amp\weakClosure;

final class ConstantRateLimit implements WebsocketRateLimit
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var array<int, int> */
    private array $bytesReadInLastSecond = [];

    /** @var array<int, int> */
    private array $framesReadInLastSecond = [];

    /** @var Suspension[] */
    private array $rateSuspensions = [];

    private readonly string $watcher;

    /**
     * @param positive-int $bytesPerSecondLimit
     * @param positive-int $framesPerSecondLimit
     */
    public function __construct(
        private readonly int $bytesPerSecondLimit = 1048576, // 1MB
        private readonly int $framesPerSecondLimit = 100,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->bytesPerSecondLimit <= 0) {
            throw new \ValueError('Bytes-per-second limit must be greater than 0');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->framesPerSecondLimit <= 0) {
            throw new \ValueError('Frames-per-second limit must be greater than 0');
        }

        $this->watcher = EventLoop::repeat(1, weakClosure(function (string $watcher): void {
            $this->bytesReadInLastSecond = [];
            $this->framesReadInLastSecond = [];

            if (!empty($this->rateSuspensions)) {
                EventLoop::unreference($watcher);

                foreach ($this->rateSuspensions as $suspension) {
                    $suspension->resume();
                }

                $this->rateSuspensions = [];
            }
        }));

        EventLoop::unreference($this->watcher);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function notifyBytesReceived(int $clientId, int $byteCount): void
    {
        $count = $this->bytesReadInLastSecond[$clientId] = ($this->bytesReadInLastSecond[$clientId] ?? 0) + $byteCount;

        if ($count >= $this->bytesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$clientId] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
            $suspension->suspend();
        }
    }

    public function notifyFramesReceived(int $clientId, int $frameCount): void
    {
        $count = $this->framesReadInLastSecond[$clientId] = ($this->framesReadInLastSecond[$clientId] ?? 0) + $frameCount;

        if ($count >= $this->framesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$clientId] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
            $suspension->suspend();
        }
    }
}
