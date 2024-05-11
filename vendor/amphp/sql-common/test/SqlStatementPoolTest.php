<?php declare(strict_types=1);

namespace Amp\Sql\Common\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Sql\Common\SqlStatementPool;
use Amp\Sql\SqlConnectionPool;
use Amp\Sql\SqlStatement;
use function Amp\delay;

class SqlStatementPoolTest extends AsyncTestCase
{
    public function testActiveStatementsRemainAfterTimeout()
    {
        $pool = $this->createMock(SqlConnectionPool::class);
        $pool->method('isClosed')
            ->willReturn(false);
        $pool->method('getIdleTimeout')
            ->willReturn(60);

        $statement = $this->createMock(SqlStatement::class);
        $statement->method('isClosed')
            ->willReturn(false);
        $statement->method('getQuery')
            ->willReturn('SELECT 1');
        $statement->method('getLastUsedAt')
            ->willReturn(\time());
        $statement->expects($this->once())
            ->method('execute');

        $statementPool = $this->getMockBuilder(SqlStatementPool::class)
            ->setConstructorArgs([$pool, 'SELECT 1', $this->createCallback(1, fn () => $statement)])
            ->getMockForAbstractClass();

        $this->assertFalse($statementPool->isClosed());
        $this->assertSame(\time(), $statementPool->getLastUsedAt());

        delay(1.5); // Give timeout watcher enough time to execute.

        $statementPool->execute();

        $this->assertFalse($statementPool->isClosed());
        $this->assertSame(\time(), $statementPool->getLastUsedAt());
    }

    public function testIdleStatementsRemovedAfterTimeout()
    {
        $pool = $this->createMock(SqlConnectionPool::class);
        $pool->method('isClosed')
            ->willReturn(false);
        $pool->method('getIdleTimeout')
            ->willReturn(1);

        $createStatement = function (): SqlStatement {
            $statement = $this->createMock(SqlStatement::class);
            $statement->method('isClosed')
                ->willReturn(false);
            $statement->method('getQuery')
                ->willReturn('SELECT 1');
            $statement->method('getLastUsedAt')
                ->willReturn(\time());
            $statement->expects($this->once())
                ->method('execute');

            return $statement;
        };

        $statementPool = $this->getMockBuilder(SqlStatementPool::class)
            ->setConstructorArgs([$pool, 'SELECT 1', $this->createCallback(2, $createStatement)])
            ->getMockForAbstractClass();

        $this->assertFalse($statementPool->isClosed());
        $this->assertSame(\time(), $statementPool->getLastUsedAt());

        $statementPool->execute();

        delay(1.5); // Give timeout watcher enough time to execute.

        $statementPool->execute();

        $this->assertFalse($statementPool->isClosed());
        $this->assertSame(\time(), $statementPool->getLastUsedAt());
    }
}
