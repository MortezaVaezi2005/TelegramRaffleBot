<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\RedisException;

final class Authenticator implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        #[\SensitiveParameter] private readonly string $password,
        private readonly RedisConnector $connector
    ) {
    }

    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        $connection = $this->connector->connect($cancellation);

        $connection->send('AUTH', $this->password);

        if (!($connection->receive()?->unwrap())) {
            throw new RedisException('Failed to authenticate to redis instance: ' . $connection->getName());
        }

        return $connection;
    }
}
