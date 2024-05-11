<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\Cache\Test\StringCacheTest;
use Amp\File\FileCache;
use Amp\Sync\LocalKeyedMutex;

class FileCacheTest extends StringCacheTest
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

    protected function createCache(): FileCache
    {
        return new FileCache(Fixture::path(), new LocalKeyedMutex());
    }
}
