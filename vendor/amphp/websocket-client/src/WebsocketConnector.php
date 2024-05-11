<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\Cancellation;
use Amp\Http\Client\HttpException;

interface WebsocketConnector
{
    /**
     * @throws HttpException Thrown if the request fails.
     * @throws WebsocketConnectException If the response received is invalid or is not a switching protocols (101) response.
     */
    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection;
}
