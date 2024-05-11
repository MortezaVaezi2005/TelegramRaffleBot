<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\File\KeyedFileMutex;
use Amp\Sync\AbstractKeyedMutexTest;
use Amp\Sync\KeyedMutex;

final class KeyedFileMutexTest extends AbstractKeyedMutexTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Fixture::init();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Fixture::clear();
    }

    public function createMutex(): KeyedMutex
    {
        return new KeyedFileMutex(Fixture::path());
    }
}
