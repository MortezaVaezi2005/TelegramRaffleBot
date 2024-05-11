<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Websocket\Compression\WebsocketCompressionContext;

final class Rfc6455FrameCompilerFactory implements WebsocketFrameCompilerFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function createFrameCompiler(
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): Rfc6455FrameCompiler {
        return new Rfc6455FrameCompiler($masked, $compressionContext);
    }
}
