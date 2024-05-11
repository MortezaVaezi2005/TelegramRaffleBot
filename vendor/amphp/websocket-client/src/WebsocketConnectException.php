<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\Http\Client\HttpException;
use Amp\Http\Client\Response;

final class WebsocketConnectException extends HttpException
{
    public function __construct(
        string $message,
        private readonly Response $response,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
