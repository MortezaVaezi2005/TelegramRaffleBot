<?php declare(strict_types=1);

namespace Amp\File\Test\Driver;

use Amp\File;
use Amp\File\Driver\UvFilesystemDriver;
use Amp\File\Test\FilesystemDriverTest;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\UvDriver as UvLoopDriver;

class UvFilesystemDriverTest extends FilesystemDriverTest
{
    /**
     * @dataProvider symlinkPathProvider
     *
     */
    public function testResolveSymlinkError(\Closure $linkResolver): void
    {
        if (\version_compare(\phpversion('uv'), '0.3.0', '<')) {
            $this->markTestSkipped('UvDriver Test Skipped: Causes Crash');
        }

        parent::testResolveSymlinkError($linkResolver);
    }

    protected function createDriver(): File\FilesystemDriver
    {
        if (!\extension_loaded("uv")) {
            $this->markTestSkipped("ext-uv not loaded");
        }

        $loop = EventLoop::getDriver();

        if (!$loop instanceof UvLoopDriver) {
            $this->markTestSkipped("Loop driver must be using ext-uv");
        }

        return new UvFilesystemDriver($loop);
    }
}
