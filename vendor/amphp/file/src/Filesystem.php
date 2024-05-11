<?php declare(strict_types=1);

namespace Amp\File;

final class Filesystem
{
    private FilesystemDriver $driver;

    public function __construct(FilesystemDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Open a handle for the specified path.
     *
     * @throws FilesystemException
     */
    public function openFile(string $path, string $mode): File
    {
        return $this->driver->openFile($path, $mode);
    }

    /**
     * Execute a file stat operation.
     *
     * If the requested path does not exist, it will return NULL.
     *
     * @param string $path File system path.
     */
    public function getStatus(string $path): ?array
    {
        return $this->driver->getStatus($path);
    }

    /**
     * Same as {@see Filesystem::getStatus()} except if the path is a link then the link's data is returned.
     *
     * If the requested path does not exist, it will return NULL.
     *
     * @param string $path File system path.
     */
    public function getLinkStatus(string $path): ?array
    {
        return $this->driver->getLinkStatus($path);
    }

    /**
     * Does the specified path exist?
     *
     * This function should never resolve as a failure -- only a successful bool value
     * indicating the existence of the specified path.
     *
     * @param string $path File system path.
     */
    public function exists(string $path): bool
    {
        $result = $this->getStatus($path);

        return $result !== null;
    }

    /**
     * Retrieve the size in bytes of the file at the specified path.
     *
     * If the path does not exist or is not a regular file, this method will throw.
     *
     * @param string $path File system path.
     *
     * @return int Size in bytes.
     *
     * @throws FilesystemException If the path does not exist or is not a file.
     */
    public function getSize(string $path): int
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            throw new FilesystemException("Failed to read file size for '{$path}'");
        }

        if ($result['mode'] & 0100000) {
            return $result["size"];
        }

        throw new FilesystemException("Failed to read file size for '{$path}'; specified path is not a regular file");
    }

    /**
     * Does the specified path exist and is it a directory?
     *
     * @param string $path File system path.
     */
    public function isDirectory(string $path): bool
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            return false;
        }

        return (bool) ($result['mode'] & 0040000);
    }

    /**
     * Does the specified path exist and is it a file?
     *
     * @param string $path File system path.
     */
    public function isFile(string $path): bool
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            return false;
        }

        return (bool) ($result['mode'] & 0100000);
    }

    /**
     * Does the specified path exist and is it a symlink?
     *
     * If the path does not exist, this method will return FALSE.
     *
     * @param string $path File system path.
     */
    public function isSymlink(string $path): bool
    {
        $result = $this->getLinkStatus($path);
        if ($result === null) {
            return false;
        }

        return ($result['mode'] & 0120000) === 0120000;
    }

    /**
     * Retrieve the path's last modification time as a unix timestamp.
     *
     * @param string $path File system path.
     *
     * @throws FilesystemException If the path does not exist.
     */
    public function getModificationTime(string $path): int
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            throw new FilesystemException("Failed to read file modification time for '{$path}'");
        }

        return $result["mtime"];
    }

    /**
     * Retrieve the path's last access time as a unix timestamp.
     *
     * @param string $path File system path.
     *
     * @throws FilesystemException If the path does not exist.
     */
    public function getAccessTime(string $path): int
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            throw new FilesystemException("Failed to read file access time for '{$path}'");
        }

        return $result["atime"];
    }

    /**
     * Retrieve the path's creation time as a unix timestamp.
     *
     * @param string $path File system path.
     *
     * @throws FilesystemException If the path does not exist.
     */
    public function getCreationTime(string $path): int
    {
        $result = $this->getStatus($path);
        if ($result === null) {
            throw new FilesystemException("Failed to read file creation time for '{$path}'");
        }

        return $result["ctime"];
    }

    /**
     * Create a symlink $link pointing to the file/directory located at $original.
     *
     * @throws FilesystemException If the operation fails.
     */
    public function createSymlink(string $original, string $link): void
    {
        $this->driver->createSymlink($original, $link);
    }

    /**
     * Create a hard link $link pointing to the file/directory located at $target.
     */
    public function createHardlink(string $target, string $link): void
    {
        $this->driver->createHardlink($target, $link);
    }

    /**
     * Resolve the symlink at $path.
     *
     * @throws FilesystemException
     */
    public function resolveSymlink(string $path): string
    {
        return $this->driver->resolveSymlink($path);
    }

    /**
     * Move / rename a file or directory.
     *
     *
     * @throws FilesystemException If the operation fails.
     */
    public function move(string $from, string $to): void
    {
        $this->driver->move($from, $to);
    }

    /**
     * Delete a file.
     *
     * @throws FilesystemException If the operation fails.
     */
    public function deleteFile(string $path): void
    {
        $this->driver->deleteFile($path);
    }

    /**
     * Create a directory.
     *
     * @throws FilesystemException If the operation fails.
     */
    public function createDirectory(string $path, int $mode = 0777): void
    {
        $this->driver->createDirectory($path, $mode);
    }

    /**
     * Create a directory recursively.
     *
     * @throws FilesystemException If the operation fails.
     */
    public function createDirectoryRecursively(string $path, int $mode = 0777): void
    {
        $this->driver->createDirectoryRecursively($path, $mode);
    }

    /**
     * Delete a directory.
     *
     * @throws FilesystemException If the operation fails.
     */
    public function deleteDirectory(string $path): void
    {
        $this->driver->deleteDirectory($path);
    }

    /**
     * Retrieve an array of files and directories inside the specified path.
     *
     * Dot entries are not included in the resulting array (i.e. "." and "..").
     *
     * @return list<string>
     *
     * @throws FilesystemException If the operation fails.
     */
    public function listFiles(string $path): array
    {
        return $this->driver->listFiles($path);
    }

    /**
     * Change permissions of a file or directory.
     */
    public function changePermissions(string $path, int $mode): void
    {
        $this->driver->changePermissions($path, $mode);
    }

    /**
     * Change ownership of a file or directory.
     *
     * @param int|null $uid null to ignore
     * @param int|null $gid null to ignore
     */
    public function changeOwner(string $path, ?int $uid, ?int $gid = null): void
    {
        $this->driver->changeOwner($path, $uid, $gid);
    }

    /**
     * Update the access and modification time of the specified path.
     *
     * If the file does not exist it will be created automatically.
     *
     * @param int|null $modificationTime The touch time. If $time is not supplied, the current system time is used.
     * @param int|null $accessTime The access time. If not supplied, the modification time is used.
     */
    public function touch(string $path, ?int $modificationTime = null, ?int $accessTime = null): void
    {
        $this->driver->touch($path, $modificationTime, $accessTime);
    }

    /**
     * Buffer the specified file's contents.
     *
     * @param string $path The file path from which to buffer contents.
     */
    public function read(string $path): string
    {
        return $this->driver->read($path);
    }

    /**
     * Write the contents string to the specified path.
     *
     * @param string $path The file path to which to $contents should be written.
     * @param string $contents The data to write to the specified $path.
     */
    public function write(string $path, string $contents): void
    {
        $this->driver->write($path, $contents);
    }
}
