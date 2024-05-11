<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File\File;
use Amp\File\Internal;
use Amp\File\PendingOperationError;
use Amp\File\Whence;
use Amp\Future;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\WorkerException;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class ParallelFile implements File, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private ?int $id;

    private int $position;

    private int $size;

    /** @var bool True if an operation is pending. */
    private bool $busy = false;

    /** @var int Number of pending write operations. */
    private int $pendingWrites = 0;

    private bool $writable;

    private ?Future $closing = null;

    private readonly DeferredFuture $onClose;

    public function __construct(
        private readonly Internal\FileWorker $worker,
        int $id,
        private readonly string $path,
        int $size,
        private readonly string $mode,
    ) {
        $this->id = $id;
        $this->size = $size;
        $this->writable = $this->mode[0] !== 'r';
        $this->position = $this->mode[0] === 'a' ? $this->size : 0;

        $this->onClose = new DeferredFuture;
    }

    public function __destruct()
    {
        if ($this->id !== null && $this->worker->isRunning()) {
            $id = $this->id;
            $worker = $this->worker;
            EventLoop::queue(static fn () => $worker->execute(new Internal\FileTask('fclose', [], $id)));
        }

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function close(): void
    {
        if (!$this->worker->isRunning()) {
            return;
        }

        if ($this->closing) {
            $this->closing->await();
            return;
        }

        $this->writable = false;

        $this->closing = async(function (): void {
            $id = $this->id;
            $this->id = null;
            $this->worker->execute(new Internal\FileTask('fclose', [], $id));
        });

        try {
            $this->closing->await();
        } finally {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return $this->closing !== null;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function truncate(int $size): void
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        ++$this->pendingWrites;
        $this->busy = true;

        try {
            $this->worker->execute(new Internal\FileTask('ftruncate', [$size], $this->id));
            $this->size = $size;
        } catch (TaskFailureException $exception) {
            throw new StreamException("Reading from the file failed", 0, $exception);
        } catch (WorkerException $exception) {
            throw new StreamException("Sending the task to the worker failed", 0, $exception);
        } finally {
            if (--$this->pendingWrites === 0) {
                $this->busy = false;
            }
        }
    }

    public function eof(): bool
    {
        return $this->pendingWrites === 0 && $this->size <= $this->position;
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        $this->busy = true;

        try {
            $data = $this->worker->execute(new Internal\FileTask('fread', [$length], $this->id), $cancellation);

            if ($data !== null) {
                $this->position += \strlen($data);
            }
        } catch (TaskFailureException $exception) {
            throw new StreamException("Reading from the file failed", 0, $exception);
        } catch (WorkerException $exception) {
            throw new StreamException("Sending the task to the worker failed", 0, $exception);
        } finally {
            $this->busy = false;
        }

        return $data;
    }

    public function write(string $bytes): void
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy && $this->pendingWrites === 0) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        ++$this->pendingWrites;
        $this->busy = true;

        try {
            $this->worker->execute(new Internal\FileTask('fwrite', [$bytes], $this->id));
            $this->position += \strlen($bytes);
            $this->size = \max($this->position, $this->size);
        } catch (TaskFailureException $exception) {
            throw new StreamException("Writing to the file failed", 0, $exception);
        } catch (WorkerException $exception) {
            throw new StreamException("Sending the task to the worker failed", 0, $exception);
        } finally {
            if (--$this->pendingWrites === 0) {
                $this->busy = false;
            }
        }
    }

    public function end(): void
    {
        $this->writable = false;
        $this->close();
    }

    public function seek(int $position, Whence $whence = Whence::Start): int
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        switch ($whence) {
            case Whence::Start:
            case Whence::Current:
            case Whence::End:
                try {
                    $this->position = $this->worker->execute(
                        new Internal\FileTask('fseek', [$position, $whence], $this->id)
                    );

                    $this->size = \max($this->position, $this->size);

                    return $this->position;
                } catch (TaskFailureException $exception) {
                    throw new StreamException('Seeking in the file failed.', 0, $exception);
                } catch (WorkerException $exception) {
                    throw new StreamException("Sending the task to the worker failed", 0, $exception);
                }

            default:
                throw new \Error('Invalid whence value. Use Start, Current, or End.');
        }
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isReadable(): bool
    {
        return $this->id !== null;
    }

    public function isSeekable(): bool
    {
        return $this->id !== null;
    }

    public function isWritable(): bool
    {
        return $this->id !== null && $this->writable;
    }
}
