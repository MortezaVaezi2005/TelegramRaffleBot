<?php declare(strict_types=1);

namespace Amp\Websocket;

interface WebsocketRateLimit
{
    /**
     * Notify the rate limiter of bytes received by the client. The rate limiter may suspend if it wishes to
     * prevent processing data and receiving further data.
     *
     * @param positive-int $byteCount
     */
    public function notifyBytesReceived(int $clientId, int $byteCount): void;

    /**
     * Notify the rate limiter of frames received by the client. The rate limiter may suspend if it wishes to
     * prevent processing data and receiving further data.
     *
     * @param positive-int $frameCount
     */
    public function notifyFramesReceived(int $clientId, int $frameCount): void;
}
