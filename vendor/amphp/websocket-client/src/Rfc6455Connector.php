<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Websocket;
use Amp\Websocket\Compression\Rfc7692CompressionFactory;
use Amp\Websocket\Compression\WebsocketCompressionContextFactory;

final class Rfc6455Connector implements WebsocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly HttpClient $httpClient;

    /**
     * @param WebsocketCompressionContextFactory|null $compressionContextFactory Use null to disable compression.
     */
    public function __construct(
        private readonly WebsocketConnectionFactory $connectionFactory = new Rfc6455ConnectionFactory(),
        HttpClient $httpClient = null,
        private readonly ?WebsocketCompressionContextFactory $compressionContextFactory = new Rfc7692CompressionFactory(),
    ) {
        $this->httpClient = $httpClient
            ?? (new HttpClientBuilder)->usingPool(
                new UnlimitedConnectionPool(
                    new DefaultConnectionFactory(connectContext: (new ConnectContext)->withTcpNoDelay())
                )
            )->build();
    }

    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        $key = Websocket\generateKey();
        $request = $this->generateRequest($handshake, $key);

        $deferred = new DeferredFuture();
        $connectionFactory = $this->connectionFactory;
        $compressionContextFactory = $this->compressionContextFactory;

        $request->setUpgradeHandler(static function (
            Socket $socket,
            Request $request,
            Response $response,
        ) use (
            $connectionFactory,
            $compressionContextFactory,
            $deferred,
            $key,
        ): void {
            if (\strtolower($response->getHeader('upgrade') ?? '') !== 'websocket') {
                $deferred->error(new WebsocketConnectException('Upgrade header does not equal "websocket"', $response));
                return;
            }

            if (!Websocket\validateAcceptForKey($response->getHeader('sec-websocket-accept') ?? '', $key)) {
                $deferred->error(new WebsocketConnectException('Invalid Sec-WebSocket-Accept header', $response));
                return;
            }

            $extensions = Http\splitHeader($response, 'sec-websocket-extensions') ?? [];

            foreach ($extensions as $extension) {
                if ($compressionContext = $compressionContextFactory?->fromServerHeader($extension)) {
                    break;
                }
            }

            $deferred->complete(
                $connectionFactory->createConnection($response, $socket, $compressionContext ?? null)
            );
        });

        $response = $this->httpClient->request($request, $cancellation);

        if ($response->getStatus() !== Http\HttpStatus::SWITCHING_PROTOCOLS) {
            throw new WebsocketConnectException(\sprintf(
                'A %s (%d) response was not received; instead received response status: %s (%d)',
                Http\HttpStatus::getReason(Http\HttpStatus::SWITCHING_PROTOCOLS),
                Http\HttpStatus::SWITCHING_PROTOCOLS,
                $response->getReason(),
                $response->getStatus()
            ), $response);
        }

        return $deferred->getFuture()->await();
    }

    private function generateRequest(WebsocketHandshake $handshake, string $key): Request
    {
        $uri = $handshake->getUri();
        $uri = $uri->withScheme($uri->getScheme() === 'wss' ? 'https' : 'http');

        $request = new Request($uri, 'GET');
        $request->setHeaders($handshake->getHeaders());

        $request->setTcpConnectTimeout($handshake->getTcpConnectTimeout());
        $request->setTlsHandshakeTimeout($handshake->getTlsHandshakeTimeout());
        $request->setHeaderSizeLimit($handshake->getHeaderSizeLimit());

        $extensions = Http\splitHeader($request, 'sec-websocket-extensions');

        if ($this->compressionContextFactory && \extension_loaded('zlib')) {
            $extensions[] = $this->compressionContextFactory->createRequestHeader();
        }

        if (!empty($extensions)) {
            $request->setHeader('sec-websocket-extensions', \implode(', ', $extensions));
        }

        $request->setProtocolVersions(['1.1']);
        $request->setHeader('connection', 'Upgrade');
        $request->setHeader('upgrade', 'websocket');
        $request->setHeader('sec-websocket-version', '13');
        $request->setHeader('sec-websocket-key', $key);

        return $request;
    }
}
