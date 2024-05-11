<?php declare(strict_types=1);

namespace Amp\Sql\Common;

use Amp\Cancellation;
use Amp\CompositeException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnection;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlConnector;

/**
 * @template TConfig of SqlConfig
 * @template TConnection of SqlConnection
 * @implements SqlConnector<TConfig, TConnection>
 */
final class RetrySqlConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param SqlConnector<TConfig, TConnection> $connector
     */
    public function __construct(
        private readonly SqlConnector $connector,
        private readonly int $maxTries = 3,
    ) {
        if ($maxTries <= 0) {
            throw new \Error('The number of tries must be 1 or greater');
        }
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
    {
        $tries = 0;
        $exceptions = [];

        do {
            try {
                return $this->connector->connect($config, $cancellation);
            } catch (SqlConnectionException $exception) {
                $exceptions[] = $exception;
            }
        } while (++$tries < $this->maxTries);

        $name = $config->getHost() . ':' . $config->getPort();

        throw new SqlConnectionException(
            "Could not connect to database server at {$name} after {$tries} tries",
            0,
            new CompositeException($exceptions)
        );
    }
}
