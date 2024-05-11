<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Test\AsyncFileTest;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;

class ParallelFileTest extends AsyncFileTest
{
    private const DEFAULT_WORKER_LIMIT = 8;

    private WorkerPool $pool;

    protected function createDriver(int $workerLimit = self::DEFAULT_WORKER_LIMIT): File\FilesystemDriver
    {
        $this->pool = new ContextWorkerPool();

        return new ParallelFilesystemDriver($this->pool, $workerLimit);
    }

    protected function tearDown(): void
    {
        $this->pool->shutdown();
    }

    public function getWorkerLimits(): iterable
    {
        return \array_map(fn (int $count): array => [$count], \range(4, 16, 4));
    }

    /**
     * @dataProvider getWorkerLimits
     */
    public function testMultipleOpenFiles(int $maxCount)
    {
        $driver = $this->createDriver($maxCount);

        $files = [];
        for ($i = 0; $i < $maxCount * 2; ++$i) {
            $files[] = $driver->openFile(__FILE__, 'r');
        }

        foreach ($files as $file) {
            $file->close();
        }

        $this->assertSame($maxCount, $this->pool->getWorkerCount());
    }
}
