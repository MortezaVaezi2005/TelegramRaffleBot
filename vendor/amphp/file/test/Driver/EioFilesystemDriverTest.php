<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\EioFilesystemDriver;
use Amp\File\Test\FilesystemDriverTest;
use Revolt\EventLoop;

class EioFilesystemDriverTest extends FilesystemDriverTest
{
    protected function createDriver(): File\FilesystemDriver
    {
        if (!\extension_loaded("eio")) {
            $this->markTestSkipped("eio extension not loaded");
        }

        return new EioFilesystemDriver(EventLoop::getDriver());
    }
}
