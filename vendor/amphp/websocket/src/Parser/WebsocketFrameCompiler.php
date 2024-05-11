<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

interface WebsocketFrameCompiler
{
    /**
     * Provides stateful compilation of websocket frames. Continuation frames must be proceeded by an initial text
     * or binary frame. Another text or binary frame cannot be sent until a final continuation frame is sent.
     * Control frames may be interleaved.
     */
    public function compileFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): string;
}
