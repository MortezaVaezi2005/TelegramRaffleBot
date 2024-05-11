<?php declare(strict_types=1);

namespace Amp\Redis\Protocol;

final class RedisError implements RedisResponse
{
    public function __construct(
        private readonly string $message,
    ) {
    }

    /**
     * @throws QueryException
     */
    public function unwrap(): never
    {
        throw new QueryException($this->message);
    }

    public function getKind(): ?string
    {
        $prefix = \strtok($this->message, ' ');

        // This is just a convention of Redis server, not part of the protocol
        if ($prefix === \strtoupper($prefix)) {
            return $prefix;
        }

        return null;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
