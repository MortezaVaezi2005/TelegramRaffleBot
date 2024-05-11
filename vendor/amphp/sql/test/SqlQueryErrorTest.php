<?php declare(strict_types=1);

namespace Amp\Sql\Test;

use Amp\Sql\SqlQueryError;
use PHPUnit\Framework\TestCase;

class SqlQueryErrorTest extends TestCase
{
    /**
     * @test
     */
    public function testItPassesQueryAlong()
    {
        $error = new SqlQueryError('error', 'SELECT * FROM foo');

        self::assertSame('SELECT * FROM foo', $error->getQuery());
        self::assertStringStartsWith("Amp\Sql\SqlQueryError: error\nCurrent query was SELECT * FROM foo", (string) $error);
    }
}
