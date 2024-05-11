<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\WebsocketException;

final class WebsocketParserException extends WebsocketException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
