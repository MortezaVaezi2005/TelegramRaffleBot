<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Compression\WebsocketCompressionContext;

interface WebsocketParserFactory
{
    public function createParser(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketParser;
}
