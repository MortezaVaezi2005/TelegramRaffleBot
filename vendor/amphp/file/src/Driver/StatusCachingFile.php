<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\Whence;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class StatusCachingFile implements File, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private readonly File $file;

    private readonly \Closure $invalidateCallback;

    /**
     * @param File $file Decorated instance.
     * @param \Closure $invalidateCallback Invalidation callback.
     *
     * @internal
     */
    public function __construct(File $file, \Closure $invalidateCallback)
    {
        $this->file = $file;
        $this->invalidateCallback = $invalidateCallback;
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        return $this->file->read($cancellation, $length);
    }

    public function write(string $bytes): void
    {
        try {
            $this->file->write($bytes);
        } finally {
            $this->invalidate();
        }
    }

    public function end(): void
    {
        try {
            $this->file->end();
        } finally {
            $this->invalidate();
        }
    }

    public function close(): void
    {
        $this->file->close();
    }

    public function isClosed(): bool
    {
        return $this->file->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->file->onClose($onClose);
    }

    public function seek(int $position, Whence $whence = Whence::Start): int
    {
        return $this->file->seek($position, $whence);
    }

    public function tell(): int
    {
        return $this->file->tell();
    }

    public function eof(): bool
    {
        return $this->file->eof();
    }

    public function getPath(): string
    {
        return $this->file->getPath();
    }

    public function getMode(): string
    {
        return $this->file->getMode();
    }

    public function truncate(int $size): void
    {
        try {
            $this->file->truncate($size);
        } finally {
            $this->invalidate();
        }
    }

    public function isReadable(): bool
    {
        return $this->file->isReadable();
    }

    public function isSeekable(): bool
    {
        return $this->file->isSeekable();
    }

    public function isWritable(): bool
    {
        return $this->file->isWritable();
    }

    private function invalidate(): void
    {
        ($this->invalidateCallback)();
    }
}
