<?php declare(strict_types=1);

namespace Amp\Websocket\Compression;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class Rfc7692CompressionFactory implements WebsocketCompressionContextFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function fromClientHeader(string $headerIn, ?string &$headerOut): ?WebsocketCompressionContext
    {
        return Rfc7692Compression::fromClientHeader($headerIn, $headerOut);
    }

    public function fromServerHeader(string $header): ?WebsocketCompressionContext
    {
        return Rfc7692Compression::fromServerHeader($header);
    }

    public function createRequestHeader(): string
    {
        return Rfc7692Compression::createRequestHeader();
    }
}
