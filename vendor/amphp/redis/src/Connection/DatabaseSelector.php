<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\RedisException;

final class DatabaseSelector implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly int $database,
        private readonly RedisConnector $connector
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        $connection->send('SELECT', (string) $this->database);

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to select database: ' . $connection->getName());
        }

        return $connection;
    }
}
