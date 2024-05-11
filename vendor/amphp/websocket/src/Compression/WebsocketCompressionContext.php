<?php declare(strict_types=1);

namespace Amp\Websocket\Compression;

interface WebsocketCompressionContext
{
    /**
     * @return int The RSV value for this compression extension.
     */
    public function getRsv(): int;

    /**
     * @return int Minimum number of bytes a message must be before compressing.
     */
    public function getCompressionThreshold(): int;

    /**
     * Compress the given payload data.
     */
    public function compress(string $data, bool $isFinal): string;

    /**
     * Decompress the given payload data. Null should be returned if decompression fails.
     */
    public function decompress(string $data, bool $isFinal): ?string;
}
