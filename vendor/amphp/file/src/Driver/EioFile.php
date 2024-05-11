<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File\Internal;
use Amp\File\PendingOperationError;
use Amp\Future;

final class EioFile extends Internal\QueuedWritesFile
{
    private readonly Internal\EioPoll $poll;

    /** @var resource eio file handle. */
    private $fh;

    private ?Future $closing = null;

    private readonly DeferredFuture $onClose;

    /**
     * @param resource $fh
     */
    public function __construct(Internal\EioPoll $poll, $fh, string $path, string $mode, int $size)
    {
        parent::__construct($path, $mode, $size);

        $this->poll = $poll;
        $this->fh = $fh;

        $this->onClose = new DeferredFuture;
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        if ($this->isReading || !$this->queue->isEmpty()) {
            throw new PendingOperationError;
        }

        $this->isReading = true;

        $remaining = $this->size - $this->position;
        $length = \min($length, $remaining);

        $deferred = new DeferredFuture;
        $this->poll->listen();

        $onRead = function (DeferredFuture $deferred, $result, $req): void {
            $this->isReading = false;

            if ($deferred->isComplete()) {
                return;
            }

            if ($result === -1) {
                $error = \eio_get_last_error($req);
                if ($error === "Bad file descriptor") {
                    $deferred->error(new ClosedException("Reading from the file failed due to a closed handle"));
                } else {
                    $deferred->error(new StreamException("Reading from the file failed:" . $error));
                }
            } else {
                $this->position += \strlen($result);
                $deferred->complete(\strlen($result) ? $result : null);
            }
        };

        $request = \eio_read(
            $this->fh,
            $length,
            $this->position,
            \EIO_PRI_DEFAULT,
            $onRead,
            $deferred
        );

        $id = $cancellation?->subscribe(function (\Throwable $exception) use ($request, $deferred): void {
            $this->isReading = false;
            $deferred->error($exception);
            \eio_cancel($request);
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

        $this->closing = $this->onClose->getFuture();
        $this->poll->listen();

        \eio_close($this->fh, \EIO_PRI_DEFAULT, static function (DeferredFuture $deferred): void {
            // Ignore errors when closing file, as the handle will become invalid anyway.
            $deferred->complete();
        }, $this->onClose);

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

        $onWrite = function (DeferredFuture $deferred, $result, $req) use ($position): void {
            if ($this->queue->isEmpty()) {
                $deferred->error(new ClosedException('No pending write, the file may have been closed'));
            }

            $this->queue->shift();

            if ($result === -1) {
                $error = \eio_get_last_error($req);
                if ($error === "Bad file descriptor") {
                    $deferred->error(new ClosedException("Writing to the file failed due to a closed handle"));
                } else {
                    $deferred->error(new StreamException("Writing to the file failed: " . $error));
                }
            } else {
                if ($this->position === $position) {
                    $this->position += $result;
                }

                $this->size = \max($this->size, $position + $result);

                $deferred->complete();
            }

            $this->poll->done();
        };

        \eio_write(
            $this->fh,
            $data,
            $length,
            $this->position,
            \EIO_PRI_DEFAULT,
            $onWrite,
            $deferred
        );

        return $deferred->getFuture();
    }

    protected function trim(int $size): Future
    {
        $deferred = new DeferredFuture;
        $this->poll->listen();

        $onTruncate = function (DeferredFuture $deferred, $result, $req) use ($size): void {
            if ($this->queue->isEmpty()) {
                $deferred->error(new ClosedException('No pending write, the file may have been closed'));
            }

            $this->queue->shift();
            if ($this->queue->isEmpty()) {
                $this->isReading = false;
            }

            if ($result === -1) {
                $error = \eio_get_last_error($req);
                if ($error === "Bad file descriptor") {
                    $deferred->error(new ClosedException("Truncating the file failed due to a closed handle"));
                } else {
                    $deferred->error(new StreamException("Truncating the file failed: " . $error));
                }
            } else {
                $this->size = $size;
                $this->poll->done();
                $deferred->complete();
            }
        };

        \eio_ftruncate(
            $this->fh,
            $size,
            \EIO_PRI_DEFAULT,
            $onTruncate,
            $deferred
        );

        return $deferred->getFuture();
    }
}
