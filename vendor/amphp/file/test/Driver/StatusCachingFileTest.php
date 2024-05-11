<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Test\FileTest;

class StatusCachingFileTest extends FileTest
{
    protected function createDriver(): File\FilesystemDriver
    {
        return new File\Driver\StatusCachingFilesystemDriver(new BlockingFilesystemDriver);
    }
}
