<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\RedisException;
use Amp\Socket;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;

final class SocketRedisConnector implements RedisConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly ConnectContext $connectContext;

    public function __construct(
        private readonly string $uri,
        ConnectContext $connectContext,
        private readonly ?SocketConnector $socketConnector = null,
    ) {
        $this->connectContext = $connectContext;
    }

    /**
     * @throws CancelledException
     * @throws RedisException
     * @throws RedisConnectionException
     */
    public function connect(?Cancellation $cancellation = null): RedisConnection
    {
        try {
            $socketConnector = $this->socketConnector ?? Socket\socketConnector();
            $socket = $socketConnector->connect($this->uri, $this->connectContext, $cancellation);
        } catch (Socket\SocketException $e) {
            throw new RedisConnectionException(
                'Failed to connect to redis instance (' . $this->uri . ')',
                0,
                $e
            );
        }

        return new SocketRedisConnection($socket);
    }
}
