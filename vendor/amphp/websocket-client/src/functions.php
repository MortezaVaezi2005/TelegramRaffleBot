<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\Cancellation;
use Amp\Http\Client\HttpException;
use Psr\Http\Message\UriInterface as PsrUri;
use Revolt\EventLoop;

/**
 * Set or access the global websocket Connector instance.
 */
function websocketConnector(?WebsocketConnector $connector = null): WebsocketConnector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    return $map[$driver] ??= new Rfc6455Connector();
}

/**
 * @throws WebsocketConnectException If the response received is invalid or is not a switching protocols (101) response.
 * @throws HttpException Thrown if the request fails.
 */
function connect(
    WebsocketHandshake|PsrUri|string $handshake,
    ?Cancellation $cancellation = null,
): WebsocketConnection {
    if (!$handshake instanceof WebsocketHandshake) {
        $handshake = new WebsocketHandshake($handshake);
    }

    return websocketConnector()->connect($handshake, $cancellation);
}
