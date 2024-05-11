--TEST--
File is automatically closed if unreferenced
--SKIPIF--
<?php if (!extension_loaded('uv')) die('skip ext/uv must be installed'); ?>
--FILE--
<?php

require __DIR__ . '/../../vendor/autoload.php';

use Amp\File\Filesystem;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Driver\UvFilesystemDriver;
use Revolt\EventLoop;
use function Amp\File\filesystem;

EventLoop::setDriver(new EventLoop\Driver\UvDriver());
filesystem(new UvFilesystemDriver(EventLoop::getDriver()));

$pid = getmypid();

$openFilesBefore = (int) `lsof -a -p $pid 2>/dev/null | wc -l`;

for ($i = 0; $i < 100; $i++) {
    Amp\File\openFile(sys_get_temp_dir() . '/amphp-test.txt', 'a');
}

Revolt\EventLoop::run();

$openFilesAfter = (int) `lsof -a -p $pid 2>/dev/null | wc -l`;

var_dump($openFilesBefore === $openFilesAfter);

?>
--EXPECT--
bool(true)