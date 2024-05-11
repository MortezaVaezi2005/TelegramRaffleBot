<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Parser\WebsocketParserFactory;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketHeartbeatQueue;
use Amp\Websocket\WebsocketRateLimit;

final class Rfc6455ConnectionFactory implements WebsocketConnectionFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly ?WebsocketHeartbeatQueue $heartbeatQueue = null,
        private readonly ?WebsocketRateLimit $rateLimit = null,
        private readonly WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(
            messageSizeLimit: Rfc6455Connection::DEFAULT_MESSAGE_SIZE_LIMIT,
            frameSizeLimit: Rfc6455Connection::DEFAULT_FRAME_SIZE_LIMIT,
        ),
        private readonly int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ) {
    }

    public function createConnection(
        Response $handshakeResponse,
        Socket $socket,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketConnection {
        $client = new Rfc6455Client(
            socket: $socket,
            masked: true,
            parserFactory: $this->parserFactory,
            compressionContext: $compressionContext,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimit: $this->rateLimit,
            frameSplitThreshold: $this->frameSplitThreshold,
            closePeriod: $this->closePeriod,
        );

        return new Rfc6455Connection($client, $handshakeResponse);
    }
}
