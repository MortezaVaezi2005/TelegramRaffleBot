<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\File\FileMutex;
use Amp\Sync\AbstractMutexTest;
use Amp\Sync\Mutex;

final class FileMutexTest extends AbstractMutexTest
{
    public function createMutex(): Mutex
    {
        return new FileMutex(\tempnam(\sys_get_temp_dir(), 'mutex-') . '.lock');
    }
}
