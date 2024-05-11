# websocket-client

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/websocket-client` provides an asynchronous WebSocket client for PHP based on Amp.
Websockets are full-duplex communication channels, which are mostly used for realtime communication where the HTTP request / response cycle has too much overhead.
They're also used if the server should be able to push data to the client without an explicit request.

There are various use cases for a WebSocket client in PHP, such as consuming realtime APIs, writing tests for a WebSocket server, or controlling web browsers via their remote debugging APIs, which are based on WebSockets.

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```
composer require amphp/websocket-client
```

## Requirements

- PHP 8.1+

## Usage

### Connecting

You can create new WebSocket connections using `Amp\Websocket\connect()` or calling `connect()` on an instance of `WebsocketConnector`.
The `connect()` function accepts a string, PSR-7 `UriInterface` instance, or a `WebsocketHandshake` as first argument. URIs must use the `ws` or `wss` (WebSocket over TLS) scheme.

Custom connection parameters can be specified by passing a `WebsocketHandshake` object instead of a string as first argument, which can also be used to pass additional headers with the initial handshake. The second argument is an optional `Cancellation` which may be used to cancel the connection attempt.

```php
<?php

require 'vendor/autoload.php';

use function Amp\Websocket\Client\connect;

// Amp\Websocket\Client\connect() uses the WebsocketConnection instance
// defined by Amp\Websocket\Client\websocketConnector()
$connection = connect('ws://localhost:1337/websocket');

foreach ($connection as $message) {
    // $message is an instance of Amp\Websocket\WebsocketMessage
}
```

#### Custom Connection Parameters

If necessary, a variety of connection parameters and behaviors may be altered by providing a customized instance of `WebsocketConnectionFactory` to the `WebsocketConnector` used to establish a WebSocket connection.

```php
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;

$connectionFactory = new Rfc6455ConnectionFactory(
    heartbeatQueue: new PeriodicHeartbeatQueue(
        heartbeatPeriod: 5, // 5 seconds
    ),
    rateLimit: new ConstantRateLimit(
        bytesPerSecondLimit: 2 ** 17, // 128 KiB
        framesPerSecondLimit: 10,
    ),
    parserFactory: new Rfc6455ParserFactory(
        messageSizeLimit: 2 ** 20, // 1 MiB
    ),
    frameSplitThreshold: 2 ** 14, // 16 KiB
    closePeriod: 0.5, // 0.5 seconds
);

$connector = new Rfc6455Connector($connectionFactory);

$handshake = new WebsocketHandshake('wss://example.com/websocket')
$connection = $connector->connect($handshake);
```

### Sending Data

WebSocket messages can be sent using the `Connection::sendText()` and `Connection::sendBinary()` methods.
Text messages sent with `Connection::sendText()` must be valid UTF-8.
Binary messages send with `Connection::sendBinary()` can be arbitrary data.

Both methods return as soon as the message has been fully written to the send buffer. This does not mean that the message is guaranteed to have been received by the other party.

### Receiving Data

WebSocket messages can be received using the `Connection::receive()` method. `Connection::receive()` returns a `WebsocketMessage` instance once the client has started to receive a message. This allows streaming WebSocket messages, which might be pretty large. In practice, most messages are rather small, and it's fine buffering them completely by either calling `WebsocketMessage::buffer()` or casting the object to a string. The maximum length of a message is defined by the option given to the `WebsocketParserFactory` instance provided to the `WebsocketConnectionFactory` (10 MiB by default).

```php
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\WebsocketCloseCode;
use function Amp\Websocket\Client\connect;

// Connects to the websocket endpoint at libwebsockets.org
// which sends a message every 50ms.
$handshake = (new WebsocketHandshake('wss://libwebsockets.org'))
    ->withHeader('Sec-WebSocket-Protocol', 'dumb-increment-protocol');

$connection = connect($handshake);

foreach ($connection as $message) {
    $payload = $message->buffer();

    printf("Received: %s\n", $payload);

    if ($payload === '100') {
        $connection->close();
        break;
    }
}
```

## Versioning

`amphp/websocket-client` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
