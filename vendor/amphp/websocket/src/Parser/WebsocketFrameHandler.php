<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

interface WebsocketFrameHandler
{
    /**
     * Invoked each time a frame is received by the parser.
     */
    public function handleFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): void;
}
