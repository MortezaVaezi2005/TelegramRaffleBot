<?php declare(strict_types=1);

namespace Amp\Http\Http2;

final class Http2StreamException extends \Exception
{
    public function __construct(
        string $message,
        private readonly int $streamId,
        int $code,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStreamId(): int
    {
        return $this->streamId;
    }
}
