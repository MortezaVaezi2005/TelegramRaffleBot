<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\DeferredFuture;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Amp\File\Internal;
use Revolt\EventLoop\Driver as EventLoopDriver;

final class EioFilesystemDriver implements FilesystemDriver
{
    /**
     * @return bool Determines if this driver can be used based on the environment.
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('eio');
    }

    private Internal\EioPoll $poll;

    public function __construct(EventLoopDriver $driver)
    {
        $this->poll = new Internal\EioPoll($driver);
    }

    public function openFile(string $path, string $mode): EioFile
    {
        $flags = \EIO_O_NONBLOCK | $this->parseMode($mode);
        if (\defined('\EIO_O_FSYNC')) {
            $flags |= \EIO_O_FSYNC;
        }

        $chmod = ($flags & \EIO_O_CREAT) ? 0644 : 0;

        $deferred = new DeferredFuture;
        $this->poll->listen();

        \eio_open(
            $path,
            $flags,
            $chmod,
            \EIO_PRI_DEFAULT,
            function (mixed $data, mixed $fileHandle, mixed $resource) use ($mode, $path, $deferred): void {
                if ($fileHandle === -1) {
                    $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
                } else {
                    \eio_fstat(
                        $fileHandle,
                        \EIO_PRI_DEFAULT,
                        function (mixed $data, mixed $result, mixed $resource) use (
                            $fileHandle,
                            $path,
                            $mode,
                            $deferred
                        ) {
                            if ($result === -1) {
                                $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
                            } else {
                                $handle = new EioFile($this->poll, $fileHandle, $path, $mode, $result["size"]);
                                $deferred->complete($handle);
                            }
                        }
                    );
                }
            }
        );

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function getStatus(string $path): ?array
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_stat($path, $priority, [$this, "onStat"], $deferred);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function getLinkStatus(string $path): ?array
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_lstat($path, $priority, [$this, "onLstat"], $deferred);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createSymlink(string $target, string $link): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_symlink($target, $link, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createHardlink(string $target, string $link): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_link($target, $link, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function resolveSymlink(string $target): string
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        /** @psalm-suppress InvalidArgument */
        \eio_readlink($target, $priority, [$this, "onReadlink"], $deferred);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function move(string $from, string $to): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_rename($from, $to, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function deleteFile(string $path): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        $result = \eio_unlink($path, $priority, [$this, "onUnlink"], $deferred);

        // For a non-existent file eio_unlink immediately returns true and the callback is never called.
        /** @psalm-suppress TypeDoesNotContainType */
        if ($result === true) {
            $deferred->error(new FilesystemException('File does not exist.'));
        }

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createDirectory(string $path, int $mode = 0777): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        \eio_mkdir($path, $mode, \EIO_PRI_DEFAULT, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createDirectoryRecursively(string $path, int $mode = 0777): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;

        $path = \str_replace("/", DIRECTORY_SEPARATOR, $path);
        $path = \rtrim($path, DIRECTORY_SEPARATOR);
        $arrayPath = \explode(DIRECTORY_SEPARATOR, $path);
        $tmpPath = "";

        $callback = function () use (
            &$callback,
            &$arrayPath,
            &$tmpPath,
            $mode,
            $priority,
            $deferred
        ): void {
            $tmpPath .= DIRECTORY_SEPARATOR . \array_shift($arrayPath);

            if (empty($arrayPath)) {
                \eio_mkdir($tmpPath, $mode, $priority, [$this, "onGenericResult"], $deferred);
            } else {
                \eio_mkdir($tmpPath, $mode, $priority, $callback);
            }
        };

        $callback();

        try {
            $deferred->getFuture()->await();
        } catch (FilesystemException $exception) {
            $result = $this->getStatus($path);
            if ($result !== null && ($result['mode'] & 0040000)) {
                return;
            }

            throw $exception;
        } finally {
            $this->poll->done();
        }
    }

    public function deleteDirectory(string $path): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_rmdir($path, $priority, [$this, "onRmdir"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function listFiles(string $path): array
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $flags = \EIO_READDIR_STAT_ORDER | \EIO_READDIR_DIRS_FIRST;
        $priority = \EIO_PRI_DEFAULT;

        /** @psalm-suppress InvalidArgument */
        \eio_readdir($path, $flags, $priority, [$this, "onScandir"], $deferred);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function changePermissions(string $path, int $mode): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_chmod($path, $mode, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_chown($path, $uid ?? -1, $gid ?? -1, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function touch(string $path, ?int $modificationTime, ?int $accessTime): void
    {
        $modificationTime = $modificationTime ?? \time();
        $accessTime = $accessTime ?? $modificationTime;

        if (!$this->getStatus($path)) {
            $this->openFile($path, 'c')->close();
        }

        $deferred = new DeferredFuture;
        $this->poll->listen();

        $priority = \EIO_PRI_DEFAULT;
        \eio_utime($path, $accessTime, $modificationTime, $priority, [$this, "onGenericResult"], $deferred);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function read(string $path): string
    {
        $flags = \EIO_O_RDONLY;
        $mode = 0;
        $priority = \EIO_PRI_DEFAULT;

        $deferred = new DeferredFuture;
        $this->poll->listen();

        \eio_open($path, $flags, $mode, $priority, [$this, "onGetOpen"], $deferred);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function write(string $path, string $contents): void
    {
        $flags = \EIO_O_RDWR | \EIO_O_CREAT | \EIO_O_TRUNC;
        $mode = \EIO_S_IRUSR | \EIO_S_IWUSR | \EIO_S_IXUSR;
        $priority = \EIO_PRI_DEFAULT;

        $deferred = new DeferredFuture;
        $this->poll->listen();

        $data = [$contents, $deferred];
        \eio_open($path, $flags, $mode, $priority, [$this, "onPutOpen"], $data);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    private function parseMode(string $mode): int
    {
        $mode = \str_replace(['b', 't', 'e'], '', $mode);

        switch ($mode) {
            case 'r':
                return \EIO_O_RDONLY;
            case 'r+':
                return \EIO_O_RDWR;
            case 'w':
                return \EIO_O_WRONLY | \EIO_O_TRUNC | \EIO_O_CREAT;
            case 'w+':
                return \EIO_O_RDWR | \EIO_O_TRUNC | \EIO_O_CREAT;
            case 'a':
                return \EIO_O_WRONLY | \EIO_O_APPEND | \EIO_O_CREAT;
            case 'a+':
                return \EIO_O_RDWR | \EIO_O_APPEND | \EIO_O_CREAT;
            case 'x':
                return \EIO_O_WRONLY | \EIO_O_CREAT | \EIO_O_EXCL;
            case 'x+':
                return \EIO_O_RDWR | \EIO_O_CREAT | \EIO_O_EXCL;
            case 'c':
                return \EIO_O_WRONLY | \EIO_O_CREAT;
            case 'c+':
                return \EIO_O_RDWR | \EIO_O_CREAT;
            default:
                throw new \Error('Invalid file mode: ' . $mode);
        }
    }

    private function onStat(DeferredFuture $deferred, mixed $result): void
    {
        if ($result === -1) {
            $deferred->complete();
        } else {
            $deferred->complete($result);
        }
    }

    private function onLstat(DeferredFuture $deferred, mixed $result): void
    {
        if ($result === -1) {
            $deferred->complete();
        } else {
            $deferred->complete($result);
        }
    }

    private function onReadlink(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete($result);
        }
    }

    private function onGenericResult(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete();
        }
    }

    private function onUnlink(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete();
        }
    }

    private function onRmdir(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete();
        }
    }

    private function onScandir(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $result = $result["names"];
            \sort($result);
            $deferred->complete($result);
        }
    }

    private function onGetOpen(DeferredFuture $deferred, mixed $result, mixed $resource): void
    {
        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            \eio_fstat($result, \EIO_PRI_DEFAULT, [$this, "onGetFstat"], [$result, $deferred]);
        }
    }

    private function onGetFstat(array $fileHandleAndDeferred, mixed $result, mixed $resource): void
    {
        [$fh, $deferred] = $fileHandleAndDeferred;

        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
            return;
        }

        \eio_read($fh, $result["size"], 0, \EIO_PRI_DEFAULT, [$this, "onGetRead"], $fileHandleAndDeferred);
    }

    private function onGetRead(array $fileHandleAndDeferred, mixed $result, mixed $resource): void
    {
        [$fileHandle, $deferred] = $fileHandleAndDeferred;

        \eio_close($fileHandle);

        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete($result);
        }
    }

    private function onPutOpen(array $data, mixed $result, mixed $resource): void
    {
        [$contents, $deferred] = $data;

        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $length = \strlen($contents);
            $offset = 0;
            $priority = \EIO_PRI_DEFAULT;
            $callback = [$this, "onPutWrite"];
            $fhAndPromisor = [$result, $deferred];
            \eio_write($result, $contents, $length, $offset, $priority, $callback, $fhAndPromisor);
        }
    }

    private function onPutWrite(array $fileHandleAndDeferred, mixed $result, mixed $resource): void
    {
        [$fileHandle, $deferred] = $fileHandleAndDeferred;

        \eio_close($fileHandle);

        if ($result === -1) {
            $deferred->error(new FilesystemException(\eio_get_last_error($resource)));
        } else {
            $deferred->complete();
        }
    }
}
