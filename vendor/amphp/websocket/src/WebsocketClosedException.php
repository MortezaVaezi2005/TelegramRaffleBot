<?php declare(strict_types=1);

namespace Amp\Websocket;

final class WebsocketClosedException extends WebsocketException
{
    private readonly string $reason;

    public function __construct(string $message, int $code, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf(
            '%s; Code %s (%s); Reason: "%s"',
            $message,
            $code,
            WebsocketCloseCode::getName($code) ?? 'Unknown code',
            $reason,
        ), $code, $previous);

        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
