<?php declare(strict_types=1);

namespace Amp\Sql\Common\Test;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Sql\Common\SqlCommonConnectionPool;
use Amp\Sql\Common\Test\Stub\StubSqlPooledResult;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnection;
use Amp\Sql\SqlConnector;
use Amp\Sql\SqlResult;
use function Amp\async;
use function Amp\delay;

class SqlCommonConnectionPoolTest extends AsyncTestCase
{
    public function testInvalidMaxConnections()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pool must contain at least one connection');

        $this->getMockBuilder(SqlCommonConnectionPool::class)
            ->setConstructorArgs([
                $this->createMock(SqlConfig::class),
                $this->createMock(SqlConnector::class),
                0,
            ])
            ->getMock();
    }

    private function createConnector(): SqlConnector
    {
        $now = \time();

        $connector = $this->createMock(SqlConnector::class);
        $connector->method('connect')
            ->willReturnCallback(function () use ($now): SqlConnection {
                $connection = $this->createMock(SqlConnection::class);
                $connection->method('getLastUsedAt')
                    ->willReturn($now);

                $connection->method('isClosed')
                    ->willReturn(false);

                $connection->method('query')
                    ->willReturnCallback(function () {
                        delay(0.1);
                        return $this->createMock(SqlResult::class);
                    });

                return $connection;
            });

        return $connector;
    }

    private function createPool(SqlConnector $connector, int $maxConnections = 100, int $idleTimeout = 10): SqlCommonConnectionPool
    {
        $pool = $this->getMockBuilder(SqlCommonConnectionPool::class)
            ->setConstructorArgs([
                $this->createMock(SqlConfig::class),
                $connector,
                $maxConnections,
                $idleTimeout,
            ])
            ->getMockForAbstractClass();

        $pool->method('createResult')
            ->willReturnCallback(fn (SqlResult $result, \Closure $release) => new StubSqlPooledResult($result, $release));

        return $pool;
    }

    public function testIdleConnectionsRemovedAfterTimeout()
    {
        $connector = $this->createConnector();
        $pool = $this->createPool($connector, 10, 2);

        $count = 3;

        $futures = [];
        for ($i = 0; $i < $count; ++$i) {
            $futures[] = async(fn () => $pool->query("SELECT $i"));
        }

        $this->assertCount($count, Future\await($futures));

        unset($futures); // Remove references to results so they are destructed.

        $this->assertSame($count, $pool->getConnectionCount());

        delay(1);

        $this->assertSame($count, $pool->getConnectionCount());

        delay(1);

        $this->assertSame(0, $pool->getConnectionCount());
    }

    public function testMaxConnectionCount()
    {
        $connector = $this->createConnector();
        $pool = $this->createPool($connector, $maxConnections = 3);

        $count = 10;

        $futures = [];
        for ($i = 0; $i < $count; ++$i) {
            $futures[] = async(fn () => \iterator_to_array($pool->query("SELECT $i")));
        }

        $expectedRuntime = 0.1 * \ceil($count / $maxConnections);

        $this->setMinimumRuntime($expectedRuntime);
        $this->setTimeout($expectedRuntime + 1);

        Future\await($futures);

        $this->assertSame($maxConnections, $pool->getConnectionCount());
    }
}
