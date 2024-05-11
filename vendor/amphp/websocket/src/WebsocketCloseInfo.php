<?php declare(strict_types=1);

namespace Amp\Websocket;

final class WebsocketCloseInfo
{
    public function __construct(
        private readonly int $code,
        private readonly string $reason,
        private readonly float $timestamp,
        private readonly bool $byPeer,
    ) {
    }

    /**
     * @return float Unix timestamp (including fractions of a second) at which the connection was closed.
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * See {@see WebsocketCloseCode} for protocol-defined close codes. Note that close codes are not limited to those
     * defined by the protocol.
     */
    public function getCode(): int
    {
        return $this->code;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return bool `true` if the connection was closed by the peer.
     */
    public function isByPeer(): bool
    {
        return $this->byPeer;
    }
}
