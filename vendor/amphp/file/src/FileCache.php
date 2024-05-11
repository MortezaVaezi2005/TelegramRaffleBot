<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cache\CacheException;
use Amp\Cache\StringCache;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use Revolt\EventLoop;

/**
 * A cache which stores data in files in a directory.
 */
final class FileCache implements StringCache
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly Filesystem $filesystem;

    private readonly string $directory;

    private ?string $gcWatcher;

    public function __construct(
        string $directory,
        private readonly KeyedMutex $mutex,
        ?Filesystem $filesystem = null,
    ) {
        $filesystem ??= filesystem();
        $this->filesystem = $filesystem;
        $this->directory = $directory = \rtrim($directory, "/\\");

        $gcWatcher = static function () use ($directory, $mutex, $filesystem): void {
            try {
                $files = $filesystem->listFiles($directory);

                foreach ($files as $file) {
                    if (\strlen($file) !== 70 || !\str_ends_with($file, '.cache')) {
                        continue;
                    }

                    try {
                        $lock = $mutex->acquire($file);
                    } catch (\Throwable) {
                        continue;
                    }

                    try {
                        $handle = $filesystem->openFile($directory . '/' . $file, 'r');
                        $ttl = $handle->read(length: 4);

                        if ($ttl === null || \strlen($ttl) !== 4) {
                            $handle->close();
                            continue;
                        }

                        $ttl = \unpack('Nttl', $ttl)['ttl'];
                        if ($ttl < \time()) {
                            $filesystem->deleteFile($directory . '/' . $file);
                        }
                    } catch (\Throwable) {
                        // ignore
                    } finally {
                        $lock->release();
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        };

        // trigger once, so short running scripts also GC and don't grow forever
        EventLoop::defer($gcWatcher);

        $this->gcWatcher = EventLoop::repeat(300, $gcWatcher);

        EventLoop::unreference($this->gcWatcher);
    }

    public function __destruct()
    {
        if ($this->gcWatcher !== null) {
            EventLoop::cancel($this->gcWatcher);
        }
    }

    public function get(string $key): ?string
    {
        $filename = $this->getFilename($key);

        $lock = $this->lock($filename);

        try {
            $cacheContent = $this->filesystem->read($this->directory . '/' . $filename);

            if (\strlen($cacheContent) < 4) {
                return null;
            }

            $ttl = \unpack('Nttl', \substr($cacheContent, 0, 4))['ttl'];
            if ($ttl < \time()) {
                $this->filesystem->deleteFile($this->directory . '/' . $filename);

                return null;
            }

            $value = \substr($cacheContent, 4);

            \assert(\is_string($value));

            return $value;
        } catch (\Throwable) {
            return null;
        } finally {
            $lock->release();
        }
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        if ($ttl < 0) {
            throw new \Error("Invalid cache TTL ({$ttl}); integer >= 0 or null required");
        }

        $filename = $this->getFilename($key);

        $lock = $this->lock($filename);

        if ($ttl === null) {
            $ttl = \PHP_INT_MAX;
        } else {
            $ttl = \time() + $ttl;
        }

        $encodedTtl = \pack('N', $ttl);

        try {
            $this->filesystem->write($this->directory . '/' . $filename, $encodedTtl . $value);
        } finally {
            $lock->release();
        }
    }

    public function delete(string $key): ?bool
    {
        $filename = $this->getFilename($key);

        $lock = $this->lock($filename);

        try {
            $this->filesystem->deleteFile($this->directory . '/' . $filename);
        } catch (FilesystemException) {
            return false;
        } finally {
            $lock->release();
        }

        return true;
    }

    private static function getFilename(string $key): string
    {
        return \hash('sha256', $key) . '.cache';
    }

    private function lock(string $key): Lock
    {
        try {
            return $this->mutex->acquire($key);
        } catch (\Throwable $exception) {
            throw new CacheException(
                \sprintf('Exception thrown when obtaining the lock for key "%s"', $key),
                0,
                $exception
            );
        }
    }
}
