<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Cancellation;
use Amp\Postgres\Internal\PostgresHandleConnection;
use Amp\Sql\Common\RetrySqlConnector;
use Amp\Sql\SqlConnector;
use Amp\Sql\SqlException;
use Revolt\EventLoop;

/**
 * @param SqlConnector<PostgresConfig, PostgresHandleConnection>|null $connector
 *
 * @return SqlConnector<PostgresConfig, PostgresHandleConnection>
 */
function postgresConnector(?SqlConnector $connector = null): SqlConnector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    /**
     * @psalm-suppress InvalidArgument
     * @var SqlConnector<PostgresConfig, PostgresHandleConnection>
     */
    return $map[$driver] ??= new RetrySqlConnector(new DefaultPostgresConnector());
}

/**
 * Create a connection using the global Connector instance.
 *
 * @throws SqlException If connecting fails.
 *
 * @throws \Error If neither ext-pgsql or pecl-pq is loaded.
 */
function connect(PostgresConfig $config, ?Cancellation $cancellation = null): PostgresHandleConnection
{
    return postgresConnector()->connect($config, $cancellation);
}
