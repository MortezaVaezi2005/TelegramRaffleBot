<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnector;

/**
 * @implements SqlConnector<MysqlConfig, MysqlConnection>
 */
final class SocketMysqlConnector implements SqlConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(private readonly ?Socket\SocketConnector $connector = null)
    {
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): MysqlConnection
    {
        if (!$config instanceof MysqlConfig) {
            throw new \TypeError(\sprintf("Must provide an instance of %s to MySQL connectors", MysqlConfig::class));
        }

        $connector = $this->connector ?? Socket\socketConnector();

        return SocketMysqlConnection::connect($connector, $config, $cancellation);
    }
}
