<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

interface WebsocketParser
{
    /**
     * Parse websocket frames from peer data, invoking {@see WebsocketFrameHandler::handleFrame()} for each frame.
     *
     * @throws WebsocketParserException
     */
    public function push(string $data): void;

    /**
     * Cancel parsing and free any associated resources.
     */
    public function cancel(): void;
}
