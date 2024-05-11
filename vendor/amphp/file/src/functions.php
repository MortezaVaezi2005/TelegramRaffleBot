<?php declare(strict_types=1);

namespace Amp\File;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Driver\EioFilesystemDriver;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Driver\StatusCachingFilesystemDriver;
use Amp\File\Driver\UvFilesystemDriver;
use Revolt\EventLoop;

/**
 * Retrieve the application-wide filesystem instance.
 *
 * @param FilesystemDriver|null $driver Use the specified object as the application-wide filesystem instance.
 *
 */
function filesystem(?FilesystemDriver $driver = null): Filesystem
{
    static $map;
    $map ??= new \WeakMap();

    $loop = EventLoop::getDriver();

    if ($driver === null) {
        if (isset($map[$loop])) {
            return $map[$loop];
        }

        $defaultDriver = createDefaultDriver();

        if (!\defined("AMP_WORKER")) { // Prevent caching in workers, cache in parent instead.
            $defaultDriver = new StatusCachingFilesystemDriver($defaultDriver);
        }

        $filesystem = new Filesystem($defaultDriver);
    } else {
        $filesystem = new Filesystem($driver);
    }

    if (\defined("AMP_WORKER") && $driver instanceof ParallelFilesystemDriver) {
        throw new \Error("Cannot use the parallel driver within a worker");
    }

    $map[$loop] = $filesystem;

    return $filesystem;
}

/**
 * Create a new filesystem driver best-suited for the current environment.
 */
function createDefaultDriver(): FilesystemDriver
{
    $driver = EventLoop::getDriver();

    if (UvFilesystemDriver::isSupported($driver)) {
        /** @var EventLoop\Driver\UvDriver $driver */
        return new UvFilesystemDriver($driver);
    }

    if (EioFilesystemDriver::isSupported()) {
        return new EioFilesystemDriver($driver);
    }

    if (\defined("AMP_WORKER")) { // Prevent spawning infinite workers.
        return new BlockingFilesystemDriver;
    }

    return new ParallelFilesystemDriver;
}

/**
 * Open a handle for the specified path.
 *
 * @throws FilesystemException
 */
function openFile(string $path, string $mode): File
{
    return filesystem()->openFile($path, $mode);
}

/**
 * Execute a file stat operation.
 *
 * If the requested path does not exist the function will return NULL.
 *
 * @param string $path File system path.
 */
function getStatus(string $path): ?array
{
    return filesystem()->getStatus($path);
}

/**
 * Same as {@see Filesystem::getStatus()} except if the path is a link then the link's data is returned.
 *
 * If the requested path does not exist the function will return NULL.
 *
 * @param string $path File system path.
 */
function getLinkStatus(string $path): ?array
{
    return filesystem()->getLinkStatus($path);
}

/**
 * Does the specified path exist?
 *
 * This function should never resolve as a failure -- only a successful bool value
 * indicating the existence of the specified path.
 *
 * @param string $path File system path.
 */
function exists(string $path): bool
{
    return filesystem()->exists($path);
}

/**
 * Retrieve the size in bytes of the file at the specified path.
 *
 * @param string $path File system path.
 */
function getSize(string $path): int
{
    return filesystem()->getSize($path);
}

/**
 * Does the specified path exist and is it a directory?
 *
 * @param string $path File system path.
 */
function isDirectory(string $path): bool
{
    return filesystem()->isDirectory($path);
}

/**
 * Does the specified path exist and is it a file?
 *
 * @param string $path File system path.
 */
function isFile(string $path): bool
{
    return filesystem()->isFile($path);
}

/**
 * Does the specified path exist and is it a symlink?
 *
 * @param string $path File system path.
 */
function isSymlink(string $path): bool
{
    return filesystem()->isSymlink($path);
}

/**
 * Retrieve the path's last modification time as a unix timestamp.
 *
 * @param string $path File system path.
 */
function getModificationTime(string $path): int
{
    return filesystem()->getModificationTime($path);
}

/**
 * Retrieve the path's last access time as a unix timestamp.
 *
 * @param string $path File system path.
 */
function getAccessTime(string $path): int
{
    return filesystem()->getAccessTime($path);
}

/**
 * Retrieve the path's creation time as a unix timestamp.
 *
 * @param string $path File system path.
 */
function getCreationTime(string $path): int
{
    return filesystem()->getCreationTime($path);
}

/**
 * Create a symlink $link pointing to the file/directory located at $original.
 */
function createSymlink(string $original, string $link): void
{
    filesystem()->createSymlink($original, $link);
}

/**
 * Create a hard link $link pointing to the file/directory located at $target.
 */
function createHardlink(string $target, string $link): void
{
    filesystem()->createHardlink($target, $link);
}

/**
 * Resolve the symlink at $path.
 */
function resolveSymlink(string $path): string
{
    return filesystem()->resolveSymlink($path);
}

/**
 * Move / rename a file or directory.
 */
function move(string $from, string $to): void
{
    filesystem()->move($from, $to);
}

/**
 * Delete a file.
 */
function deleteFile(string $path): void
{
    filesystem()->deleteFile($path);
}

/**
 * Create a directory.
 */
function createDirectory(string $path, int $mode = 0777): void
{
    filesystem()->createDirectory($path, $mode);
}

/**
 * Create a directory recursively.
 */
function createDirectoryRecursively(string $path, int $mode = 0777): void
{
    filesystem()->createDirectoryRecursively($path, $mode);
}

/**
 * Delete a directory.
 */
function deleteDirectory(string $path): void
{
    filesystem()->deleteDirectory($path);
}

/**
 * Retrieve an array of files and directories inside the specified path.
 *
 * Dot entries are not included in the resulting array (i.e. "." and "..").
 *
 * @return list<string>
 */
function listFiles(string $path): array
{
    return filesystem()->listFiles($path);
}

/**
 * Change the permissions of a file or directory.
 */
function changePermissions(string $path, int $mode): void
{
    filesystem()->changePermissions($path, $mode);
}

/**
 * Change the ownership of a file or directory.
 *
 * @param int|null $uid null to ignore
 * @param int|null $gid null to ignore
 */
function changeOwner(string $path, ?int $uid, ?int $gid = null): void
{
    filesystem()->changeOwner($path, $uid, $gid);
}

/**
 * Update the access and modification time of the specified path.
 *
 * If the file does not exist it will be created automatically.
 *
 * @param int|null $modificationTime The touch time. If $time is not supplied, the current system time is used.
 * @param int|null $accessTime The access time. If not supplied, the modification time is used.
 */
function touch(string $path, ?int $modificationTime = null, ?int $accessTime = null): void
{
    filesystem()->touch($path, $modificationTime, $accessTime);
}

/**
 * Read the specified file's contents.
 *
 * @param string $path The file path from which to buffer contents.
 */
function read(string $path): string
{
    return filesystem()->read($path);
}

/**
 * Write the contents string to the specified path.
 *
 * @param string $path The file path to which to $contents should be written.
 * @param string $contents The data to write to the specified $path.
 */
function write(string $path, string $contents): void
{
    filesystem()->write($path, $contents);
}
