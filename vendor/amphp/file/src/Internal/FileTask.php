<?php declare(strict_types=1);

namespace Amp\File\Internal;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cache\CacheException;
use Amp\Cache\LocalCache;
use Amp\Cancellation;
use Amp\File\Driver\BlockingFile;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\FilesystemException;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/**
 * @codeCoverageIgnore
 * @internal
 * @implements Task<mixed, never, never>
 */
final class FileTask implements Task
{
    private static ?LocalCache $cache = null;

    private static ?BlockingFilesystemDriver $driver = null;

    /**
     * @param int|null $id File ID.
     *
     * @throws \Error
     */
    public function __construct(
        private readonly string $operation,
        private readonly array $args = [],
        private readonly ?int $id = null,
    ) {
        if ($operation === '') {
            throw new \Error('Operation must be a non-empty string');
        }
    }

    /**
     * @throws FilesystemException
     * @throws CacheException
     * @throws ClosedException
     * @throws StreamException
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $cache = self::$cache ??= new LocalCache();
        $driver = self::$driver ??= new BlockingFilesystemDriver();

        if ('f' === $this->operation[0]) {
            if ("fopen" === $this->operation) {
                $file = $driver->openFile(...$this->args);

                $size = $driver->getStatus($file->getPath())["size"]
                    ?? throw new FilesystemException("Could not determine file size");

                $id = $file->getId();
                $cache->set((string) $id, $file);

                return [$id, $size, $file->getMode()];
            }

            if ($this->id === null) {
                throw new FilesystemException("No file ID provided");
            }

            $id = (string) $this->id;

            $file = $cache->get($id);
            if ($file === null) {
                throw new FilesystemException(\sprintf(
                    "No file handle with the ID %d has been opened on the worker",
                    $this->id
                ));
            }

            if (!$file instanceof BlockingFile) {
                throw new FilesystemException("File storage found in inconsistent state");
            }

            switch ($this->operation) {
                case "fread":
                    return $file->read($cancellation, ...$this->args);

                case "fwrite":
                    $file->write(...$this->args);
                    return null;

                case "fseek":
                    return $file->seek(...$this->args);

                case "ftruncate":
                    $file->truncate(...$this->args);
                    return null;

                case "fclose":
                    $cache->delete($id);
                    $file->close();
                    return null;

                default:
                    throw new \Error('Invalid operation');
            }
        }

        switch ($this->operation) {
            case "getStatus":
            case "deleteFile":
            case "move":
            case "createHardlink":
            case "createSymlink":
            case "resolveSymlink":
            case "getLinkStatus":
            case "exists":
            case "createDirectory":
            case "createDirectoryRecursively":
            case "listFiles":
            case "deleteDirectory":
            case "changePermissions":
            case "changeOwner":
            case "touch":
            case "read":
            case "write":
                return $driver->{$this->operation}(...$this->args);

            default:
                throw new \Error("Invalid operation - " . $this->operation);
        }
    }
}
