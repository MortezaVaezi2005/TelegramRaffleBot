<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\File\Driver;

use Amp\DeferredFuture;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Amp\File\Internal;
use Revolt\EventLoop\Driver as EventLoopDriver;
use Revolt\EventLoop\Driver\UvDriver as UvLoopDriver;

final class UvFilesystemDriver implements FilesystemDriver
{
    /**
     * @param EventLoopDriver $driver The currently active loop driver.
     *
     * @return bool Determines if this driver can be used based on the environment.
     */
    public static function isSupported(EventLoopDriver $driver): bool
    {
        return $driver instanceof UvLoopDriver;
    }

    /** @var \UVLoop|resource Loop resource of type uv_loop or instance of \UVLoop. */
    private $eventLoopHandle;

    private readonly Internal\UvPoll $poll;

    /** @var bool True if ext-uv version is < 0.3.0. */
    private readonly bool $priorVersion;

    public function __construct(private readonly UvLoopDriver $driver)
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->eventLoopHandle = $driver->getHandle();
        $this->poll = new Internal\UvPoll($driver);
        $this->priorVersion = \version_compare(\phpversion('uv'), '0.3.0', '<');
    }

    public function openFile(string $path, string $mode): UvFile
    {
        $flags = $this->parseMode($mode);
        $chmod = ($flags & \UV::O_CREAT) ? 0644 : 0;

        $deferred = new DeferredFuture;
        $this->poll->listen();

        \uv_fs_open($this->eventLoopHandle, $path, $flags, $chmod, function ($fileHandle) use ($mode, $path, $deferred): void {
            if (\is_resource($fileHandle)) {
                $this->onOpenHandle($fileHandle, $mode, $path, $deferred);
            } else {
                $deferred->error(new FilesystemException("Failed opening file '{$path}'"));
            }
        });

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

        $callback = static function ($stat) use ($deferred, $path): void {
            if (\is_int($stat)) {
                $deferred->complete(null);
                return;
            }

            // link is not a valid stat type but returned by the uv extension
            // change link to nlink
            if (isset($stat['link'])) {
                $stat['nlink'] = $stat['link'];

                unset($stat['link']);
            }

            $deferred->complete($stat);
        };

        if ($this->priorVersion) {
            $callback = static function ($fh, $stat) use ($callback): void {
                if (empty($fh)) {
                    $stat = 0;
                }

                $callback($stat);
            };
        }

        \uv_fs_stat($this->eventLoopHandle, $path, $callback);

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

        if ($this->priorVersion) {
            $callback = static function ($fh, $stat) use ($deferred): void {
                $deferred->complete(empty($fh) ? null : $stat);
            };
        } else {
            $callback = static function ($stat) use ($deferred): void {
                $deferred->complete(\is_int($stat) ? null : $stat);
            };
        }

        \uv_fs_lstat($this->eventLoopHandle, $path, $callback);

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createSymlink(string $target, string $link): void
    {
        try {
            $this->poll->listen();

            $deferred = new DeferredFuture;
            $callback = $this->createGenericCallback($deferred, "Could not create symbolic link");
            \uv_fs_symlink($this->eventLoopHandle, $target, $link, \UV::S_IRWXU | \UV::S_IRUSR, $callback);

            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function createHardlink(string $target, string $link): void
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        \uv_fs_link($this->eventLoopHandle, $target, $link, $this->createGenericCallback($deferred, "Could not create hard link"));

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

        if ($this->priorVersion) {
            $callback = static function ($fh, $target) use ($deferred): void {
                if (!(bool) $fh) {
                    $deferred->error(new FilesystemException("Could not read symbolic link"));
                    return;
                }

                $deferred->complete($target);
            };
        } else {
            $callback = static function ($target) use ($deferred): void {
                if (\is_int($target)) {
                    $deferred->error(new FilesystemException("Could not read symbolic link"));
                    return;
                }

                $deferred->complete($target);
            };
        }

        \uv_fs_readlink($this->eventLoopHandle, $target, $callback);

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

        \uv_fs_rename($this->eventLoopHandle, $from, $to, $this->createGenericCallback($deferred, "Could not rename file"));

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

        \uv_fs_unlink($this->eventLoopHandle, $path, $this->createGenericCallback($deferred, "Could not unlink file"));

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

        \uv_fs_mkdir($this->eventLoopHandle, $path, $mode, $this->createGenericCallback($deferred, "Could not create directory"));

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

        $path = \str_replace("/", DIRECTORY_SEPARATOR, $path);
        $path = \rtrim($path, DIRECTORY_SEPARATOR);
        $arrayPath = \explode(DIRECTORY_SEPARATOR, $path);
        $tmpPath = "";

        $callback = function () use (
            &$callback,
            &$arrayPath,
            &$tmpPath,
            $mode,
            $deferred
        ): void {
            $tmpPath .= DIRECTORY_SEPARATOR . \array_shift($arrayPath);

            if (empty($arrayPath)) {
                \uv_fs_mkdir(
                    $this->eventLoopHandle,
                    $tmpPath,
                    $mode,
                    $this->createGenericCallback($deferred, "Could not create directory")
                );
            } else {
                \uv_fs_mkdir($this->eventLoopHandle, $tmpPath, $mode, $callback);
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

        \uv_fs_rmdir($this->eventLoopHandle, $path, $this->createGenericCallback($deferred, "Could not remove directory"));

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

        if ($this->priorVersion) {
            \uv_fs_readdir($this->eventLoopHandle, $path, 0, static function ($fh, $data) use ($deferred, $path): void {
                if (empty($fh) && $data !== 0) {
                    $deferred->error(new FilesystemException("Failed reading contents from {$path}"));
                } elseif ($data === 0) {
                    $deferred->complete([]);
                } else {
                    $deferred->complete($data);
                }
            });
        } else {
            /** @noinspection PhpUndefinedFunctionInspection */
            \uv_fs_scandir($this->eventLoopHandle, $path, static function ($data) use ($deferred, $path): void {
                if (\is_int($data) && $data !== 0) {
                    $deferred->error(new FilesystemException("Failed reading contents from {$path}"));
                } elseif ($data === 0) {
                    $deferred->complete([]);
                } else {
                    $deferred->complete($data);
                }
            });
        }

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

        $callback = $this->createGenericCallback($deferred, "Could not change file permissions");
        \uv_fs_chmod($this->eventLoopHandle, $path, $mode, $callback);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        // @TODO Return a failure in windows environments
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $callback = $this->createGenericCallback($deferred, "Could not change file owner");
        \uv_fs_chown($this->eventLoopHandle, $path, $uid ?? -1, $gid ?? -1, $callback);

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

        $callback = $this->createGenericCallback($deferred, "Could not touch file");
        \uv_fs_utime($this->eventLoopHandle, $path, $modificationTime, $accessTime, $callback);

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function read(string $path): string
    {
        $this->poll->listen();

        $fileHandle = $this->doFsOpen($path, flags: \UV::O_RDONLY, mode: 0);
        if (!$fileHandle) {
            throw new FilesystemException("Failed opening file handle: {$path}");
        }

        $deferred = new DeferredFuture;

        $stat = $this->doFsStat($fileHandle);

        if (empty($stat)) {
            $deferred->error(new FilesystemException("stat operation failed on open file handle"));
        } elseif (!$stat["isfile"]) {
            \uv_fs_close($this->eventLoopHandle, $fileHandle, static function () use ($deferred): void {
                $deferred->error(new FilesystemException("cannot buffer contents: path is not a file"));
            });
        } else {
            $buffer = $this->doFsRead($fileHandle, $stat["size"]);

            if ($buffer === null) {
                \uv_fs_close($this->eventLoopHandle, $fileHandle, static function () use ($deferred): void {
                    $deferred->error(new FilesystemException("read operation failed on open file handle"));
                });
            } else {
                \uv_fs_close($this->eventLoopHandle, $fileHandle, static function () use ($deferred, $buffer): void {
                    $deferred->complete($buffer);
                });
            }
        }

        try {
            return $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    public function write(string $path, string $contents): void
    {
        $flags = \UV::O_WRONLY | \UV::O_CREAT | \UV::O_TRUNC;
        $mode = \UV::S_IRWXU | \UV::S_IRUSR;

        $this->poll->listen();

        $fh = $this->doFsOpen($path, $flags, $mode);
        if (!$fh) {
            throw new FilesystemException("Failed opening write file handle");
        }

        $deferred = new DeferredFuture;

        \uv_fs_write($this->eventLoopHandle, $fh, $contents, 0, function ($fh, $result) use ($deferred): void {
            \uv_fs_close($this->eventLoopHandle, $fh, static function () use ($deferred, $result): void {
                if ($result < 0) {
                    $deferred->error(new FilesystemException(\uv_strerror($result)));
                } else {
                    $deferred->complete(null);
                }
            });
        });

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->poll->done();
        }
    }

    private function parseMode(string $mode): int
    {
        $mode = \str_replace(['b', 't', 'e'], '', $mode);

        return match ($mode) {
            "r" => \UV::O_RDONLY,
            "r+" => \UV::O_RDWR,
            "c" => \UV::O_WRONLY | \UV::O_CREAT,
            "w" => \UV::O_WRONLY | \UV::O_CREAT | \UV::O_TRUNC,
            "c+" => \UV::O_RDWR | \UV::O_CREAT,
            "w+" => \UV::O_RDWR | \UV::O_CREAT | \UV::O_TRUNC,
            "a" => \UV::O_WRONLY | \UV::O_CREAT | \UV::O_APPEND,
            "a+" => \UV::O_RDWR | \UV::O_CREAT | \UV::O_APPEND,
            "x" => \UV::O_WRONLY | \UV::O_CREAT | \UV::O_EXCL,
            "x+" => \UV::O_RDWR | \UV::O_CREAT | \UV::O_EXCL,
            default => throw new \Error('Invalid file mode'),
        };
    }

    private function onOpenHandle(mixed $fileHandle, string $mode, string $path, DeferredFuture $deferred): void
    {
        if ($mode[0] === "w") {
            \uv_fs_ftruncate($this->eventLoopHandle, $fileHandle, 0, function ($fileHandle) use ($mode, $path, $deferred): void {
                if (\is_resource($fileHandle)) {
                    $this->finalizeHandle($fileHandle, 0, $mode, $path, $deferred);
                } else {
                    $deferred->error(new FilesystemException("Failed truncating file $path"));
                }
            });
        } else {
            \uv_fs_fstat($this->eventLoopHandle, $fileHandle, function ($fileHandle, $stat) use ($mode, $path, $deferred): void {
                if (\is_resource($fileHandle)) {
                    $this->finalizeHandle($fileHandle, $stat["size"], $mode, $path, $deferred);
                } else {
                    $deferred->error(new FilesystemException("Failed reading file size from open handle pointing to $path"));
                }
            });
        }
    }

    private function finalizeHandle(mixed $fileHandle, int $size, string $mode, string $path, DeferredFuture $deferred): void
    {
        $deferred->complete(new UvFile($this->driver, $this->poll, $fileHandle, $path, $mode, $size));
    }

    private function doFsOpen(string $path, int $flags, int $mode): mixed
    {
        $deferred = new DeferredFuture;

        \uv_fs_open($this->eventLoopHandle, $path, $flags, $mode, static function ($fh) use ($deferred) {
            $deferred->complete($fh);
        });

        return $deferred->getFuture()->await();
    }

    private function doFsStat(mixed $fileHandle): array
    {
        $deferred = new DeferredFuture;

        \uv_fs_fstat($this->eventLoopHandle, $fileHandle, static function ($fh, $stat) use ($deferred): void {
            if (\is_resource($fh)) {
                $stat["isdir"] = (bool) ($stat["mode"] & \UV::S_IFDIR);
                $stat["isfile"] = !$stat["isdir"];
                $deferred->complete($stat);
            } else {
                $deferred->complete();
            }
        });

        return $deferred->getFuture()->await();
    }

    private function doFsRead(mixed $fileHandle, int $length): ?string
    {
        $deferred = new DeferredFuture;

        if ($this->priorVersion) {
            $callback = static function ($fileHandle, $readBytes, $buffer) use ($deferred): void {
                $deferred->complete($readBytes < 0 ? null : $buffer);
            };
        } else {
            $callback = static function ($readBytes, $buffer) use ($deferred): void {
                $deferred->complete($readBytes < 0 ? null : $buffer);
            };
        }

        \uv_fs_read($this->eventLoopHandle, $fileHandle, 0, $length, $callback);

        return $deferred->getFuture()->await();
    }

    private function doWrite(string $path, string $contents): void
    {
    }

    private function createGenericCallback(DeferredFuture $deferred, string $error): \Closure
    {
        $callback = static function (int $result) use ($deferred, $error): void {
            if ($result !== 0) {
                $deferred->error(new FilesystemException($error));
                return;
            }

            $deferred->complete(null);
        };

        if ($this->priorVersion) {
            $callback = static function (bool $result) use ($callback): void {
                $callback($result ? 0 : -1);
            };
        }

        return $callback;
    }
}
