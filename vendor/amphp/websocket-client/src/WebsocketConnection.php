<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\Http\Client\Response;
use Amp\Websocket\WebsocketClient;

interface WebsocketConnection extends WebsocketClient
{
    /**
     * @return Response Server response originating the client connection.
     */
    public function getHandshakeResponse(): Response;
}
