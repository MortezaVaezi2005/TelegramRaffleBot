<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\PHPUnit\AsyncTestCase;

abstract class FilesystemTest extends AsyncTestCase
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
}
