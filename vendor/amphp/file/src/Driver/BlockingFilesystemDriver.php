<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;

final class BlockingFilesystemDriver implements FilesystemDriver
{
    private readonly \Closure $errorHandler;

    public function __construct()
    {
        $this->errorHandler = static fn () => true;
    }

    public function openFile(string $path, string $mode): BlockingFile
    {
        $mode = \str_replace(['b', 't', 'e'], '', $mode);

        switch ($mode) {
            case "r":
            case "r+":
            case "w":
            case "w+":
            case "a":
            case "a+":
            case "x":
            case "x+":
            case "c":
            case "c+":
                break;

            default:
                throw new \Error("Invalid file mode: {$mode}");
        }

        try {
            \set_error_handler(static function (int $type, string $message) use ($path, $mode): never {
                throw new FilesystemException("Failed to open '{$path}' in mode '{$mode}': {$message}");
            });

            if (!$handle = \fopen($path, $mode . 'be')) {
                throw new FilesystemException("Failed to open '{$path}' in mode '{$mode}'");
            }

            return new BlockingFile($handle, $path, $mode);
        } finally {
            \restore_error_handler();
        }
    }

    public function getStatus(string $path): ?array
    {
        \clearstatcache(true, $path);
        \set_error_handler($this->errorHandler);

        try {
            return \stat($path) ?: null;
        } finally {
            \restore_error_handler();
        }
    }

    public function getLinkStatus(string $path): ?array
    {
        \clearstatcache(true, $path);
        \set_error_handler($this->errorHandler);

        try {
            return \lstat($path) ?: null;
        } finally {
            \restore_error_handler();
        }
    }

    public function createSymlink(string $target, string $link): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($target, $link): never {
                throw new FilesystemException("Could not create symbolic link '{$link}' to '{$target}': {$message}");
            });

            if (!\symlink($target, $link)) {
                throw new FilesystemException("Could not create symbolic link '{$link}' to '{$target}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function createHardlink(string $target, string $link): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($target, $link): never {
                throw new FilesystemException("Could not create hard link '{$link}' to '{$target}': {$message}");
            });

            if (!\link($target, $link)) {
                throw new FilesystemException("Could not create hard link '{$link}' to '{$target}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function resolveSymlink(string $target): string
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($target): never {
                throw new FilesystemException("Could not resolve symbolic link '{$target}': {$message}");
            });

            if (false === ($result = \readlink($target))) {
                throw new FilesystemException("Could not resolve symbolic link '{$target}'");
            }

            return $result;
        } finally {
            \restore_error_handler();
        }
    }

    public function move(string $from, string $to): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($from, $to): never {
                throw new FilesystemException("Could not move file from '{$from}' to '{$to}': {$message}");
            });

            if (!\rename($from, $to)) {
                throw new FilesystemException("Could not move file from '{$from}' to '{$to}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function deleteFile(string $path): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Could not delete file '{$path}': {$message}");
            });

            if (!\unlink($path)) {
                throw new FilesystemException("Could not delete file '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function createDirectory(string $path, int $mode = 0777): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Could not create directory '{$path}': {$message}");
            });

            /** @noinspection MkdirRaceConditionInspection */
            if (!\mkdir($path, $mode)) {
                throw new FilesystemException("Could not create directory '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function createDirectoryRecursively(string $path, int $mode = 0777): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): bool {
                if (!\is_dir($path)) {
                    throw new FilesystemException("Could not create directory '{$path}': {$message}");
                }

                return true;
            });

            if (\is_dir($path)) {
                return;
            }

            /** @noinspection MkdirRaceConditionInspection */
            if (!\mkdir($path, $mode, true)) {
                if (\is_dir($path)) {
                    return;
                }

                throw new FilesystemException("Could not create directory '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Could not remove directory '{$path}': {$message}");
            });

            if (!\rmdir($path)) {
                throw new FilesystemException("Could not remove directory '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function listFiles(string $path): array
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to list files in '{$path}': {$message}");
            });

            if (!\is_dir($path)) {
                throw new FilesystemException("Failed to list files; '{$path}' is not a directory");
            }

            if ($arr = \scandir($path)) {
                \clearstatcache(true, $path);

                return \array_values(\array_filter($arr, static function ($el): bool {
                    return $el !== "." && $el !== "..";
                }));
            }

            throw new FilesystemException("Failed to list files in '{$path}'");
        } finally {
            \restore_error_handler();
        }
    }

    public function changePermissions(string $path, int $mode): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to change permissions for '{$path}': {$message}");
            });

            if (!\chmod($path, $mode)) {
                throw new FilesystemException("Failed to change permissions for '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to change owner for '{$path}': {$message}");
            });

            $uid ??= -1;
            $gid ??= -1;

            if ($uid !== -1 && !\chown($path, $uid)) {
                throw new FilesystemException("Failed to change owner for '{$path}'");
            }

            if ($gid !== -1 && !\chgrp($path, $gid)) {
                throw new FilesystemException("Failed to change owner for '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function touch(string $path, ?int $modificationTime, ?int $accessTime): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to touch '{$path}': {$message}");
            });

            $modificationTime = $modificationTime ?? \time();
            $accessTime = $accessTime ?? $modificationTime;

            if (!\touch($path, $modificationTime, $accessTime)) {
                throw new FilesystemException("Failed to touch '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function read(string $path): string
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to read '{$path}': {$message}");
            });

            if (false === ($result = \file_get_contents($path))) {
                throw new FilesystemException("Failed to read '{$path}'");
            }

            return $result;
        } finally {
            \restore_error_handler();
        }
    }

    public function write(string $path, string $contents): void
    {
        try {
            \set_error_handler(static function (int $type, string $message) use ($path): never {
                throw new FilesystemException("Failed to write to '{$path}': {$message}");
            });

            if (false === \file_put_contents($path, $contents)) {
                throw new FilesystemException("Failed to write to '{$path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }
}
