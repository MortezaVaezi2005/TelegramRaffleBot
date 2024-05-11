<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Closable;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

/**
 * @extends \Traversable<int, WebsocketMessage>
 *
 * @psalm-type Timestamp = int<0, max>
 * @psalm-type Counter = int<0, max>
 */
interface WebsocketClient extends Closable, \Traversable
{
    /**
     * Receive a message from the remote Websocket endpoint.
     *
     * @param Cancellation|null $cancellation Cancel awaiting the next message. Note this does not close the
     * connection or discard the next message. A subsequent call to this method will still return the next message
     * received from the client.
     *
     * @return WebsocketMessage|null Returns the message sent by the remote or `null` if the connection closes.
     */
    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage;

    /**
     * @return int Unique identifier for the client.
     */
    public function getId(): int;

    /**
     * @return SocketAddress Local socket address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return SocketAddress Remote socket address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return TlsInfo|null TlsInfo object if connection is secure.
     */
    public function getTlsInfo(): ?TlsInfo;

    /**
     * @return WebsocketCloseInfo Throws an instance of Error if the client has not closed.
     */
    public function getCloseInfo(): WebsocketCloseInfo;

    /**
     * @return bool Determines if a compression context has been negotiated.
     */
    public function isCompressionEnabled(): bool;

    /**
     * Sends a text message to the endpoint. All data sent with this method must be valid UTF-8. Use `sendBinary()` if
     * you want to send binary data.
     *
     * @param string $data Payload to send.
     *
     * @throws WebsocketClosedException Thrown if sending to the client fails.
     */
    public function sendText(string $data): void;

    /**
     * Sends a binary message to the endpoint.
     *
     * @param string $data Payload to send.
     *
     * @throws WebsocketClosedException Thrown if sending to the client fails.
     */
    public function sendBinary(string $data): void;

    /**
     * Streams the given UTF-8 text stream to the endpoint. This method should be used only for large payloads such as
     * files. Use send() for smaller payloads.
     *
     * @throws WebsocketClosedException Thrown if sending to the client fails.
     */
    public function streamText(ReadableStream $stream): void;

    /**
     * Streams the given binary to the endpoint. This method should be used only for large payloads such as
     * files. Use sendBinary() for smaller payloads.
     *
     * @throws WebsocketClosedException Thrown if sending to the client fails.
     */
    public function streamBinary(ReadableStream $stream): void;

    /**
     * Sends a ping to the endpoint.
     */
    public function ping(): void;

    /**
     * Returns the corresponding count of events for the passed enum case.
     *
     * @return int<0, max>
     */
    public function getCount(WebsocketCount $type): int;

    /**
     * Returns the most recent unix timestamp (including fractions of a second) the given event enum case was observed,
     * or {@see \NAN} if the event has not occurred.
     *
     */
    public function getTimestamp(WebsocketTimestamp $type): float;

    /**
     * @return bool `false` if the client is still connected, `true` if the client has disconnected.
     *      Returns `true` as soon as the closing handshake is initiated by the server or client.
     */
    public function isClosed(): bool;

    /**
     * Closes the client connection.
     */
    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void;

    /**
     * Attaches a callback invoked when the client closes. The callback is passed this client as the only parameter.
     *
     * @param \Closure(int, WebsocketCloseInfo):void $onClose Function is passed the client ID and
     *      {@see WebsocketCloseInfo} object.
     */
    public function onClose(\Closure $onClose): void;
}
