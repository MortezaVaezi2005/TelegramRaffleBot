<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Test\FilesystemDriverTest;

class BlockingFilesystemDriverTest extends FilesystemDriverTest
{
    protected function createDriver(): File\FilesystemDriver
    {
        return new BlockingFilesystemDriver;
    }
}
