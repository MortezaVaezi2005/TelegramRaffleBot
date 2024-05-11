<?php declare(strict_types=1);

namespace Amp\File\Test;

use Amp\File;
use Amp\File\FilesystemException;
use const Amp\Process\IS_WINDOWS;

abstract class FilesystemDriverTest extends FilesystemTest
{
    protected File\Filesystem $driver;

    public function testListFiles(): void
    {
        $fixtureDir = Fixture::path();
        $actual = $this->driver->listFiles($fixtureDir);
        $expected = IS_WINDOWS
            ? ["dir", "dirlink", "file", "filelink"]
            : ["dir", "dirlink", "fifo", "fifolink", "file", "filelink", "linkloop"];
        $this->assertSame($expected, $actual);
    }

    public function testListFilesThrowsIfPathNotADirectory(): void
    {
        $this->expectException(FilesystemException::class);

        $this->driver->listFiles(__FILE__);
    }

    public function testListFilesThrowsIfPathDoesntExist(): void
    {
        $this->expectException(FilesystemException::class);

        $path = Fixture::path() . "/nonexistent";
        $this->driver->listFiles($path);
    }

    public function testCreateSymlink(): void
    {
        $fixtureDir = Fixture::path();

        $original = "{$fixtureDir}/file";
        $link = "{$fixtureDir}/symlink.txt";
        $this->driver->createSymlink($original, $link);
        $this->assertTrue(\is_link($link));
        $this->driver->deleteFile($link);
    }

    public function testCreateSymlinkFailWhenLinkExists(): void
    {
        $this->expectException(FilesystemException::class);

        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $this->driver->createSymlink($path, $path);
    }

    public function testCreateHardlink(): void
    {
        $fixtureDir = Fixture::path();

        $original = "{$fixtureDir}/file";
        $link = "{$fixtureDir}/hardlink.txt";
        $this->driver->createHardlink($original, $link);
        $this->assertFileExists($link);
        $this->assertFalse(\is_link($link));
        $this->driver->deleteFile($link);
    }

    public function testCreateHardlinkFailWhenLinkExists(): void
    {
        $this->expectException(FilesystemException::class);

        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $this->driver->createHardlink($path, $path);
    }

    public function testResolveSymlink(): void
    {
        $fixtureDir = Fixture::path();

        $original = "{$fixtureDir}/file";
        $link = "{$fixtureDir}/symlink.txt";
        \symlink($original, $link);

        $this->assertSame(\str_replace('/', \DIRECTORY_SEPARATOR, $original), $this->driver->resolveSymlink($link));
    }

    public function symlinkPathProvider(): array
    {
        return [
            'nonExistingPath' => [
                static function () {
                    return Fixture::path() . '/' . \uniqid('amphp-test-', true);
                },
            ],
            'notLink' => [
                static function () {
                    return Fixture::path();
                },
            ],
        ];
    }

    /**
     * @dataProvider symlinkPathProvider
     *
     */
    public function testResolveSymlinkError(\Closure $linkResolver): void
    {
        $link = $linkResolver();

        $this->expectException(FilesystemException::class);

        $result = $this->driver->resolveSymlink($link);

        if (IS_WINDOWS) {
            self::assertNotSame($result, $link);

            $this->markTestSkipped('Build directory itself contains a symlink');
        }
    }

    public function testLinkStatus(): void
    {
        $fixtureDir = Fixture::path();

        $target = "{$fixtureDir}/file";
        $link = "{$fixtureDir}/symlink.txt";
        $this->driver->createSymlink($target, $link);
        $this->assertIsArray($this->driver->getLinkStatus($link));
        $this->driver->deleteFile($link);
    }

    public function testStatus(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $stat = $this->driver->getStatus($path);
        $this->assertIsArray($stat);
        $this->assertSameStatus(\stat($path), $stat);
    }

    public function testDirectoryStatus(): void
    {
        $fixtureDir = Fixture::path();
        $stat = $this->driver->getStatus("{$fixtureDir}/dir");
        $this->assertIsArray($stat);
        $this->assertSameStatus(\stat("{$fixtureDir}/dir"), $stat);
    }

    public function testNonexistentPathStatusResolvesToNull(): void
    {
        $fixtureDir = Fixture::path();
        $stat = $this->driver->getStatus("{$fixtureDir}/nonexistent");
        $this->assertNull($stat);
    }

    public function testExists(): void
    {
        $fixtureDir = Fixture::path();
        $this->assertFalse($this->driver->exists("{$fixtureDir}/nonexistent"));
        $this->assertTrue($this->driver->exists("{$fixtureDir}/file"));
    }

    public function testRead(): void
    {
        $fixtureDir = Fixture::path();
        $this->assertSame("small", $this->driver->read("{$fixtureDir}/file"));
    }

    public function testSize(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $stat = ($this->driver->getStatus($path));
        $size = $stat["size"];
        $this->assertSame($size, $this->driver->getSize($path));
    }

    public function testSizeFailsOnNonexistentPath(): void
    {
        $this->expectException(FilesystemException::class);

        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";
        $this->driver->getSize($path);
    }

    public function testSizeFailsOnDirectoryPath(): void
    {
        $this->expectException(FilesystemException::class);

        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/dir";
        $this->assertTrue($this->driver->isDirectory($path));
        $this->driver->getSize($path);
    }

    /**
     * @dataProvider dataForDirectoryCheck
     */
    public function testIsDirectory(bool $expectedResult, string $name): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/{$name}";
        $this->assertSame($expectedResult, $this->driver->isDirectory($path));
    }

    public function dataForDirectoryCheck(): \Generator
    {
        yield 'file' => [false, 'file'];
        yield 'filelink' => [false, 'filelink'];
        yield 'dir' => [true, 'dir'];
        yield 'dirlink' => [true, 'dirlink'];
        if (\extension_loaded('posix')) {
            yield 'fifo' => [false, 'fifo'];
            yield 'fifolink' => [false, 'fifolink'];
        }
        if (!IS_WINDOWS) {
            yield 'linkloop' => [false, 'linkloop'];
        }
        yield 'nonexistent' => [false, 'nonexistent'];
    }

    /**
     * @dataProvider dataForFileCheck
     */
    public function testIsFile(bool $expectedResult, string $name): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/{$name}";
        $this->assertSame($expectedResult, $this->driver->isFile($path));
    }

    public function dataForFileCheck(): \Generator
    {
        yield 'file' => [true, 'file'];
        yield 'filelink' => [true, 'filelink'];
        yield 'dir' => [false, 'dir'];
        yield 'dirlink' => [false, 'dirlink'];
        if (\extension_loaded('posix')) {
            yield 'fifo' => [false, 'fifo'];
            yield 'fifolink' => [false, 'fifolink'];
        }
        if (!IS_WINDOWS) {
            yield 'linkloop' => [false, 'linkloop'];
        }
        yield 'nonexistent' => [false, 'nonexistent'];
    }

    /**
     * @dataProvider dataForSymlinkCheck
     */
    public function testIsSymlink(bool $expectedResult, string $name): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/{$name}";
        $this->assertSame($expectedResult, $this->driver->isSymlink($path));
    }

    public function dataForSymlinkCheck(): \Generator
    {
        yield 'file' => [false, 'file'];
        yield 'filelink' => [true, 'filelink'];
        yield 'dir' => [false, 'dir'];
        yield 'dirlink' => [true, 'dirlink'];
        if (\extension_loaded('posix')) {
            yield 'fifo' => [false, 'fifo'];
            yield 'fifolink' => [true, 'fifolink'];
        }
        if (!IS_WINDOWS) {
            yield 'linkloop' => [true, 'linkloop'];
        }
        yield 'nonexistent' => [false, 'nonexistent'];
    }

    public function testMove(): void
    {
        $fixtureDir = Fixture::path();

        $contents1 = "rename test";
        $old = "{$fixtureDir}/rename1.txt";
        $new = "{$fixtureDir}/rename2.txt";

        $this->driver->write($old, $contents1);
        $this->driver->move($old, $new);
        $contents2 = $this->driver->read($new);
        $this->driver->deleteFile($new);

        $this->assertSame($contents1, $contents2);
    }

    public function testMoveFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->move($path, $path);
    }

    public function testDeleteFile(): void
    {
        $fixtureDir = Fixture::path();
        $toUnlink = "{$fixtureDir}/unlink";
        $this->driver->getStatus($toUnlink);
        $this->driver->write($toUnlink, "unlink me");
        $this->driver->deleteFile($toUnlink);
        $this->assertNull($this->driver->getStatus($toUnlink));
    }

    public function testDeleteFileFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->deleteFile($path);
    }

    public function testDeleteFileFailsOnDirectory(): void
    {
        $fixtureDir = Fixture::path();
        $dir = "{$fixtureDir}/newdir";
        $this->driver->createDirectory($dir);

        $this->expectException(FilesystemException::class);

        $this->driver->deleteFile($dir);
    }

    public function testCreateAndDeleteDirectory(): void
    {
        $fixtureDir = Fixture::path();

        $dir = "{$fixtureDir}/newdir";

        \umask(0022);

        $this->driver->createDirectory($dir);
        $stat = $this->driver->getStatus($dir);

        if (IS_WINDOWS) {
            $this->assertSame('0777', $this->getPermissionsFromStatus($stat));
        } else {
            $this->assertSame('0755', $this->getPermissionsFromStatus($stat));
        }

        $this->driver->deleteDirectory($dir);
        $this->assertNull($this->driver->getStatus($dir));

        // test for 0, because previous array_filter made that not work
        $dir = "{$fixtureDir}/newdir/with/recursive/creation/0/1/2";

        $this->driver->createDirectoryRecursively($dir, 0764);
        $stat = $this->driver->getStatus($dir);

        if (IS_WINDOWS) {
            $this->assertSame('0777', $this->getPermissionsFromStatus($stat));
        } else {
            $this->assertSame('0744', $this->getPermissionsFromStatus($stat));
        }
    }

    public function testCreateDirectorySlashEnd(): void
    {
        $fixtureDir = Fixture::path();

        $dir = "{$fixtureDir}/newdir-with-trailing-slash/";

        $this->driver->createDirectory($dir, 0764);
        $this->assertTrue($this->driver->exists($dir));
    }

    public function testCreateDirectoryRecursivelySlashEnd(): void
    {
        $fixtureDir = Fixture::path();

        $dir = "{$fixtureDir}/newdir/with/recursive/creation/321/";

        $this->driver->createDirectoryRecursively($dir, 0764);
        $this->assertTrue($this->driver->exists($dir));
    }

    public function testCreateDirectoryRecursivelyExistsDir(): void
    {
        $this->expectNotToPerformAssertions();

        $this->driver->createDirectoryRecursively(__DIR__, 0764);
    }

    public function testCreateDirectoryRecursivelyExistsFile(): void
    {
        $this->expectException(FilesystemException::class);

        $this->driver->createDirectoryRecursively(__FILE__, 0764);
    }

    public function testCreateDirectoryFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->createDirectory($path);
    }

    public function testDeleteDirectoryFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->deleteDirectory($path);
    }

    public function testDeleteDirectoryFailsOnFile(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";

        $this->expectException(FilesystemException::class);

        $this->driver->deleteDirectory($path);
    }

    public function testMtime(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $stat = $this->driver->getStatus($path);
        $statMtime = $stat["mtime"];
        $this->assertSame($statMtime, $this->driver->getModificationTime($path));
    }

    public function testMtimeFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->getModificationTime($path);
    }

    public function testAtime(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $stat = $this->driver->getStatus($path);
        $statAtime = $stat["atime"];
        $this->assertSame($statAtime, $this->driver->getAccessTime($path));
    }

    public function testAtimeFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->getAccessTime($path);
    }

    public function testCtime(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/file";
        $stat = $this->driver->getStatus($path);
        $statCtime = $stat["ctime"];
        $this->assertSame($statCtime, $this->driver->getCreationTime($path));
    }

    public function testCtimeFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->getCreationTime($path);
    }

    /**
     * @group slow
     */
    public function testTouch(): void
    {
        $fixtureDir = Fixture::path();

        $touch = "{$fixtureDir}/touch";
        $this->driver->write($touch, "touch me");

        $oldStat = $this->driver->getStatus($touch);
        $this->driver->touch($touch, \time() + 10, \time() + 20);
        $newStat = $this->driver->getStatus($touch);
        $this->driver->deleteFile($touch);

        $this->assertTrue($newStat["atime"] > $oldStat["atime"]);
        $this->assertTrue($newStat["mtime"] > $oldStat["mtime"]);

        $this->driver->touch($touch);
        self::assertFileExists($touch);
    }

    /**
     * @group slow
     */
    public function testWrite(): void
    {
        $fixtureDir = Fixture::path();

        $contents1 = "write test longer";
        $contents2 = "write test";
        $path = "{$fixtureDir}/write.txt";

        $this->driver->write($path, $contents1);
        $this->assertSame($contents1, $this->driver->read($path));

        $this->driver->write($path, $contents2);
        $this->assertSame($contents2, $this->driver->read($path));

        $this->driver->write($path, $contents1);
        $this->assertSame($contents1, $this->driver->read($path));
    }

    public function testTouchFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->touch($path);
    }

    public function testChangePermissions(): void
    {
        $fixtureDir = Fixture::path();

        $path = "{$fixtureDir}/file";
        $stat = $this->driver->getStatus($path);
        $this->assertNotSame('0777', \substr(\decoct($stat['mode']), -4));
        $this->driver->changePermissions($path, 0777);
        $stat = $this->driver->getStatus($path);

        if (IS_WINDOWS) {
            $this->assertSame('0666', \substr(\decoct($stat['mode']), -4));
        } else {
            $this->assertSame('0777', \substr(\decoct($stat['mode']), -4));
        }
    }

    public function testChangePermissionsFailsOnNonexistentPath(): void
    {
        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->changePermissions($path, 0777);
    }

    public function testChangeOwner(): void
    {
        if (IS_WINDOWS) {
            $this->markTestSkipped('Not supported on Windows');
        }

        $fixtureDir = Fixture::path();

        $path = "{$fixtureDir}/file";
        $this->driver->getStatus($path);
        $user = \fileowner($path);
        $this->driver->changeOwner($path, $user);
        self::assertSame($user, \fileowner($path));
    }

    public function testChangeOwnerFailsOnNonexistentPath(): void
    {
        if (IS_WINDOWS) {
            $this->markTestSkipped('Not supported on Windows');
        }

        $fixtureDir = Fixture::path();
        $path = "{$fixtureDir}/nonexistent";

        $this->expectException(FilesystemException::class);

        $this->driver->changeOwner($path, 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new File\Filesystem($this->createDriver());
    }

    abstract protected function createDriver(): File\FilesystemDriver;

    private function assertSameStatus(array $expected, array $actual): void
    {
        $filter = function (array $stat) {
            $filtered = \array_filter(
                $stat,
                function (string $key): bool {
                    return !\is_numeric($key);
                },
                ARRAY_FILTER_USE_KEY
            );

            \ksort($filtered);

            return $filtered;
        };

        $this->assertSame($filter($expected), $filter($actual));
    }

    private function getPermissionsFromStatus(array $stat): string
    {
        return \substr(\decoct($stat["mode"]), 1);
    }
}
