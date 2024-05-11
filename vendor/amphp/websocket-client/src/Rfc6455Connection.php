<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Traversable;

/**
 * @implements  \IteratorAggregate<int, WebsocketMessage>
 */
final class Rfc6455Connection implements WebsocketConnection, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_MESSAGE_SIZE_LIMIT = (2 ** 20) * 10; // 10MB
    public const DEFAULT_FRAME_SIZE_LIMIT = (2 ** 20) * 10; // 10MB

    public function __construct(
        private readonly Rfc6455Client $client,
        private readonly Response $handshakeResponse,
    ) {
    }

    public function getHandshakeResponse(): Response
    {
        return $this->handshakeResponse;
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return $this->client->receive($cancellation);
    }

    public function getId(): int
    {
        return $this->client->getId();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->client->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->client->getRemoteAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->client->getTlsInfo();
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        return $this->client->getCloseInfo();
    }

    public function sendText(string $data): void
    {
        $this->client->sendText($data);
    }

    public function sendBinary(string $data): void
    {
        $this->client->sendBinary($data);
    }

    public function streamText(ReadableStream $stream): void
    {
        $this->client->streamText($stream);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->client->streamBinary($stream);
    }

    public function ping(): void
    {
        $this->client->ping();
    }

    public function getCount(WebsocketCount $type): int
    {
        return $this->client->getCount($type);
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return $this->client->getTimestamp($type);
    }

    public function isClosed(): bool
    {
        return $this->client->isClosed();
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        $this->client->close($code, $reason);
    }

    public function onClose(\Closure $onClose): void
    {
        $this->client->onClose($onClose);
    }

    public function isCompressionEnabled(): bool
    {
        return $this->client->isCompressionEnabled();
    }

    public function getIterator(): Traversable
    {
        yield from $this->client;
    }
}
