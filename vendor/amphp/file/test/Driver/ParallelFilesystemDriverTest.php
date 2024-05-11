<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Test\FilesystemDriverTest;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;

class ParallelFilesystemDriverTest extends FilesystemDriverTest
{
    private WorkerPool $pool;

    protected function createDriver(): File\FilesystemDriver
    {
        $this->pool = new ContextWorkerPool();

        return new ParallelFilesystemDriver($this->pool);
    }

    protected function tearDown(): void
    {
        $this->pool->shutdown();
    }
}
