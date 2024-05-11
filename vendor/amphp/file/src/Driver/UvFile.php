<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File\Internal;
use Amp\File\PendingOperationError;
use Amp\Future;
use Revolt\EventLoop\Driver\UvDriver as UvLoopDriver;

final class UvFile extends Internal\QueuedWritesFile
{
    private readonly Internal\UvPoll $poll;

    /** @var \UVLoop|resource */
    private $eventLoopHandle;

    /** @var resource */
    private $fh;

    private ?Future $closing = null;

    /** @var bool True if ext-uv version is < 0.3.0. */
    private readonly bool $priorVersion;

    private readonly DeferredFuture $onClose;

    /**
     * @param Internal\UvPoll $poll Poll for keeping the loop active.
     * @param resource $fh File handle.
     */
    public function __construct(
        UvLoopDriver $driver,
        Internal\UvPoll $poll,
        $fh,
        string $path,
        string $mode,
        int $size,
    ) {
        parent::__construct($path, $mode, $size);

        $this->poll = $poll;
        $this->fh = $fh;

        /** @psalm-suppress PropertyTypeCoercion */
        $this->eventLoopHandle = $driver->getHandle();
        $this->onClose = new DeferredFuture;

        $this->priorVersion = \version_compare(\phpversion('uv'), '0.3.0', '<');
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        if ($this->isReading || !$this->queue->isEmpty()) {
            throw new PendingOperationError;
        }

        $deferred = new DeferredFuture;
        $this->poll->listen();

        $this->isReading = true;

        $onRead = function ($result, $buffer) use ($deferred): void {
            $this->isReading = false;

            if ($deferred->isComplete()) {
                return;
            }

            if (\is_int($buffer)) {
                $error = \uv_strerror($buffer);
                if ($error === "bad file descriptor") {
                    $deferred->error(new ClosedException("Reading from the file failed due to a closed handle"));
                } else {
                    $deferred->error(new StreamException("Reading from the file failed: " . $error));
                }
                return;
            }

            $length = \strlen($buffer);
            $this->position += $length;
            $deferred->complete($length ? $buffer : null);
        };

        if ($this->priorVersion) {
            $onRead = static function ($fh, $result, $buffer) use ($onRead): void {
                if ($result < 0) {
                    $buffer = $result; // php-uv v0.3.0 changed the callback to put an int in $buffer on error.
                }

                $onRead($result, $buffer);
            };
        }

        \uv_fs_read($this->eventLoopHandle, $this->fh, $this->position, $length, $onRead);

        $id = $cancellation?->subscribe(function (\Throwable $exception) use ($deferred): void {
            $this->isReading = false;
            $deferred->error($exception);
        });

        try {
            return $deferred->getFuture()->await();
        } finally {
            /** @psalm-suppress PossiblyNullArgument $id is non-null if $cancellation is non-null */
            $cancellation?->unsubscribe($id);
            $this->poll->done();
        }
    }

    public function close(): void
    {
        if ($this->closing) {
            $this->closing->await();
            return;
        }

        $deferred = $this->onClose;
        $this->closing = $deferred->getFuture();
        $this->poll->listen();

        \uv_fs_close($this->eventLoopHandle, $this->fh, static function () use ($deferred): void {
            // Ignore errors when closing file, as the handle will become invalid anyway.
            $deferred->complete();
        });

        try {
            $this->closing->await();
        } finally {
            $this->poll->done();
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

    protected function push(string $data, int $position): Future
    {
        $length = \strlen($data);

        if ($length === 0) {
            return Future::complete();
        }

        $deferred = new DeferredFuture;
        $this->poll->listen();

        $onWrite = function ($fh, $result) use ($deferred, $length, $position): void {
            if ($this->queue->isEmpty()) {
                $deferred->error(new ClosedException('No pending write, the file may have been closed'));
            }

            $this->queue->shift();

            if ($result < 0) {
                $error = \uv_strerror($result);
                if ($error === "bad file descriptor") {
                    $deferred->error(new ClosedException("Writing to the file failed due to a closed handle"));
                } else {
                    $deferred->error(new StreamException("Writing to the file failed: " . $error));
                }
            } else {
                if ($this->position === $position) {
                    $this->position += $length;
                }

                $this->size = \max($this->size, $position + $length);

                $deferred->complete();
            }

            $this->poll->done();
        };

        \uv_fs_write($this->eventLoopHandle, $this->fh, $data, $position, $onWrite);

        return $deferred->getFuture();
    }

    protected function trim(int $size): Future
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $onTruncate = function ($fh) use ($deferred, $size): void {
            if ($this->queue->isEmpty()) {
                $deferred->error(new ClosedException('No pending write, the file may have been closed'));
            }

            $this->queue->shift();

            $this->size = $size;
            $deferred->complete();
            $this->poll->done();
        };

        \uv_fs_ftruncate(
            $this->eventLoopHandle,
            $this->fh,
            $size,
            $onTruncate
        );

        return $deferred->getFuture();
    }
}
