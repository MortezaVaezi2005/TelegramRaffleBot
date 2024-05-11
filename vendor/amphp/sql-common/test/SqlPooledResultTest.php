<?php declare(strict_types=1);

namespace Amp\Sql\Common\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Sql\Common\Test\Stub\StubSqlPooledResult;
use Amp\Sql\Common\Test\Stub\StubSqlResult;
use function Amp\delay;

class SqlPooledResultTest extends AsyncTestCase
{
    public function testIdleConnectionsRemovedAfterTimeout()
    {
        $invoked = false;

        $release = function () use (&$invoked) {
            $invoked = true;
        };

        $expectedRow = ['column' => 'value'];

        $secondResult = new StubSqlResult([$expectedRow]);
        $firstResult = new StubSqlResult([$expectedRow], $secondResult);
        $pooledResult = new StubSqlPooledResult(new StubSqlResult([$expectedRow], $firstResult), $release);

        $iterator = $pooledResult->getIterator();

        $this->assertSame($expectedRow, $iterator->current());

        $this->assertFalse($invoked);

        $iterator->next();
        $this->assertFalse($iterator->valid());

        $this->assertFalse($invoked); // Next result set available.

        $pooledResult = $pooledResult->getNextResult();
        $iterator = $pooledResult->getIterator();

        $this->assertSame($expectedRow, $iterator->current());

        $iterator->next();
        $this->assertFalse($iterator->valid());

        $pooledResult = $pooledResult->getNextResult();
        unset($pooledResult); // Manually unset to trigger destructor.

        delay(0); // Tick event loop to dispose of result set.

        $this->assertTrue($invoked); // No next result set, so release callback invoked.
    }
}
