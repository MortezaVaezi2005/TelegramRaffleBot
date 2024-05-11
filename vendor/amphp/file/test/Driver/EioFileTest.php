<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\EioFilesystemDriver;
use Amp\File\Test\AsyncFileTest;
use Revolt\EventLoop;

class EioFileTest extends AsyncFileTest
{
    protected function createDriver(): File\FilesystemDriver
    {
        if (!\extension_loaded("eio")) {
            $this->markTestSkipped("eio extension not loaded");
        }

        return new EioFilesystemDriver(EventLoop::getDriver());
    }
}
