<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Compression\WebsocketCompressionContext;

interface WebsocketFrameCompilerFactory
{
    public function createFrameCompiler(
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketFrameCompiler;
}
