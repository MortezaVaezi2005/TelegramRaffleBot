<?php declare(strict_types=1);

namespace Amp\File;

use Amp\Cancellation;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use Amp\Sync\SyncException;
use function Amp\delay;

final class KeyedFileMutex implements KeyedMutex
{
    private const LATENCY_TIMEOUT = 0.01;
    private const DELAY_LIMIT = 1;

    private readonly Filesystem $filesystem;

    private readonly string $directory;

    /**
     * @param string $directory Directory in which to store key files.
     */
    public function __construct(string $directory, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? filesystem();
        $this->directory = \rtrim($directory, "/\\");
    }

    public function acquire(string $key, ?Cancellation $cancellation = null): Lock
    {
        if (!$this->filesystem->isDirectory($this->directory)) {
            throw new SyncException(\sprintf('Directory "%s" does not exist or is not a directory', $this->directory));
        }

        $filename = $this->getFilename($key);

        // Try to create the lock file. If the file already exists, someone else
        // has the lock, so set an asynchronous timer and try again.
        for ($attempt = 0; true; ++$attempt) {
            try {
                $file = $this->filesystem->openFile($filename, 'x');

                // Return a lock object that can be used to release the lock on the mutex.
                $lock = new Lock(fn () => $this->release($filename));

                $file->close();

                return $lock;
            } catch (FilesystemException) {
                delay(\min(self::DELAY_LIMIT, self::LATENCY_TIMEOUT * (2 ** $attempt)), cancellation: $cancellation);
            }
        }
    }

    /**
     * Releases the lock on the mutex.
     *
     * @throws SyncException
     */
    private function release(string $filename): void
    {
        try {
            $this->filesystem->deleteFile($filename);
        } catch (\Throwable $exception) {
            throw new SyncException(
                'Failed to unlock the mutex file: ' . $filename,
                previous: $exception,
            );
        }
    }

    private function getFilename(string $key): string
    {
        return $this->directory . '/' . \hash('sha256', $key) . '.lock';
    }
}
