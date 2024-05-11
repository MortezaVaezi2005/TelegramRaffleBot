<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\File;
use Amp\File\PendingOperationError;
use function Amp\async;

abstract class AsyncFileTest extends FileTest
{
    public function testSimultaneousReads()
    {
        $this->expectException(PendingOperationError::class);

        $handle = $this->driver->openFile(__FILE__, "r");

        $future1 = async(fn () => $handle->read(length: 20));
        $future2 = async(fn () => $handle->read(length: 20));

        $expected = \substr(File\read(__FILE__), 0, 20);
        $this->assertSame($expected, $future1->await());

        $future2->await();
    }

    public function testSeekWhileReading()
    {
        $this->expectException(PendingOperationError::class);

        $handle = $this->driver->openFile(__FILE__, "r");

        $future1 = async(fn () => $handle->read(length: 10));
        $future2 = async(fn () => $handle->read(length: 0));

        $expected = \substr(File\read(__FILE__), 0, 10);
        $this->assertSame($expected, $future1->await());

        $future2->await();
    }

    public function testReadWhileWriting()
    {
        $this->expectException(PendingOperationError::class);

        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $data = "test";

        $future1 = async(fn () => $handle->write($data));
        $future2 = async(fn () => $handle->read(length: 10));

        $this->assertNull($future1->await());

        $future2->await();
    }

    public function testWriteWhileReading()
    {
        $this->expectException(PendingOperationError::class);

        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $future1 = async(fn () => $handle->read(length: 10));
        $future2 = async(fn () => $handle->write("test"));

        $this->assertNull($future1->await());

        $future2->await();
    }

    public function testCancelReadThenReadAgain()
    {
        $path = Fixture::path() . "/temp";

        $handle = $this->driver->openFile($path, "c+");

        $deferredCancellation = new DeferredCancellation();
        $deferredCancellation->cancel();

        $handle->write("test");
        $handle->seek(0);

        try {
            $handle->read(cancellation: $deferredCancellation->getCancellation(), length: 2);
            $handle->seek(0); // If the read succeeds (e.g.: ParallelFile), we need to seek back to 0.
        } catch (CancelledException) {
        }

        $this->assertSame("test", $handle->read());
    }
}
