<?php declare(strict_types=1);

namespace Amp\File;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;

interface File extends ReadableStream, WritableStream
{
    public const DEFAULT_READ_LENGTH = 8192;

    /**
     * Read $length bytes from the open file handle.
     */
    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string;

    /**
     * Set the internal pointer position.
     *
     * @return int New offset position.
     */
    public function seek(int $position, Whence $whence = Whence::Start): int;

    /**
     * Return the current internal offset position of the file handle.
     */
    public function tell(): int;

    /**
     * Test for being at the end of the stream (a.k.a. "end-of-file").
     */
    public function eof(): bool;

    /**
     * @return bool Seeking may become unavailable if the underlying source is closed or lost.
     */
    public function isSeekable(): bool;

    /**
     * Retrieve the path used when opening the file handle.
     */
    public function getPath(): string;

    /**
     * Retrieve the mode used when opening the file handle.
     */
    public function getMode(): string;

    /**
     * Truncates the file to the given length. If $size is larger than the current file size, the file is extended
     * with null bytes.
     *
     * @param int $size New file size.
     */
    public function truncate(int $size): void;
}
