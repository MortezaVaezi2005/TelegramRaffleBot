<?php declare(strict_types=1);

namespace Amp\Redis;

use Amp\Redis\Connection\Authenticator;
use Amp\Redis\Connection\DatabaseSelector;
use Amp\Redis\Connection\ReconnectingRedisLink;
use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\Connection\SocketRedisConnector;
use Amp\Socket\ConnectContext;

function createRedisConnector(RedisConfig|string $config, ?RedisConnector $connector = null): RedisConnector
{
    if (\is_string($config)) {
        $config = RedisConfig::fromUri($config);
    }

    $connector ??= new SocketRedisConnector(
        $config->getConnectUri(),
        (new ConnectContext())->withConnectTimeout($config->getTimeout())
    );

    if ($config->hasPassword()) {
        $connector = new Authenticator($config->getPassword(), $connector);
    }

    if ($config->getDatabase() !== 0) {
        $connector = new DatabaseSelector($config->getDatabase(), $connector);
    }

    return $connector;
}

function createRedisClient(RedisConfig|string $config, ?RedisConnector $connector = null): RedisClient
{
    return new RedisClient(new ReconnectingRedisLink(createRedisConnector($config, $connector)));
}
