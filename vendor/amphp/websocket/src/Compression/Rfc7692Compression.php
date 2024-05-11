<?php declare(strict_types=1);

namespace Amp\Websocket\Compression;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class Rfc7692Compression implements WebsocketCompressionContext
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_WINDOW_SIZE = 15;

    private const RSV = 0b100;
    private const MINIMUM_LENGTH = 860;
    private const EMPTY_BLOCK = "\x0\x0\xff\xff";

    private static ?\Closure $initErrorHandler = null;

    private static ?\Closure $inflateErrorHandler = null;

    private static ?\Closure $deflateErrorHandler = null;

    /**
     * Create a compression context from a header received from a websocket client request.
     *
     * @param string $headerIn Header from request.
     * @param-out string|null $headerOut Sec-Websocket-Extension response header.
     */
    public static function fromClientHeader(string $headerIn, ?string &$headerOut): ?self
    {
        return self::fromHeader(true, $headerIn, $headerOut);
    }

    /**
     * Create a compression context from a header received from a websocket server response.
     *
     * @param string $header Header from response.
     */
    public static function fromServerHeader(string $header): ?self
    {
        return self::fromHeader(false, $header);
    }

    /**
     * @return string Header value for Sec-Websocket-Extension header.
     */
    public static function createRequestHeader(): string
    {
        return 'permessage-deflate; server_no_context_takeover; client_no_context_takeover';
    }

    /**
     * Note that 8 is no longer a valid window size, {@see https://github.com/madler/zlib/issues/171}.
     *
     * @param bool   $isServer True if creating a server context, false if creating a client context.
     * @param string $headerIn Header from request.
     * @param-out string|null $headerOut Sec-Websocket-Extension response header.
     */
    private static function fromHeader(bool $isServer, string $headerIn, ?string &$headerOut = null): ?self
    {
        $headerIn = \explode(';', \strtolower($headerIn));
        $headerIn = \array_map('trim', $headerIn);

        if (\array_shift($headerIn) !== 'permessage-deflate') {
            return null;
        }

        $serverWindowSize = self::DEFAULT_WINDOW_SIZE;
        $clientWindowSize = self::DEFAULT_WINDOW_SIZE;
        $serverContextTakeover = true;
        $clientContextTakeover = true;

        $headers = [];
        $headerOut = 'permessage-deflate';

        foreach ($headerIn as $param) {
            $parts = \explode('=', $param, 2);

            if (\in_array($parts[0], $headers, true)) {
                return null; // Repeat params in header.
            }

            $headers[] = $parts[0];

            switch ($parts[0]) {
                case 'client_max_window_bits':
                    if (isset($parts[1])) {
                        $value = (int) $parts[1];

                        if ($value < 9 || $value > 15) {
                            return null; // Invalid option value.
                        }

                        $clientWindowSize = $value;
                    } elseif (!$isServer) {
                        return null;
                    }

                    $headerOut .= '; client_max_window_bits=' . $clientWindowSize;
                    break;

                case 'client_no_context_takeover':
                    $clientContextTakeover = false;
                    $headerOut .= '; client_no_context_takeover';
                    break;

                case 'server_max_window_bits':
                    if (!isset($parts[1])) {
                        return null;
                    }

                    $value = (int) $parts[1];

                    if ($value < 9 || $value > 15) {
                        return null; // Invalid option value.
                    }

                    $serverWindowSize = $value;

                    $headerOut .= '; server_max_window_bits=' . $serverWindowSize;
                    break;

                case 'server_no_context_takeover':
                    $serverContextTakeover = false;
                    $headerOut .= '; server_no_context_takeover';
                    break;

                default:
                    return null; // Unrecognized option; do not accept extension request.
            }
        }

        if ($isServer) {
            return new self($clientWindowSize, $serverWindowSize, $clientContextTakeover, $serverContextTakeover);
        }

        return new self($serverWindowSize, $clientWindowSize, $serverContextTakeover, $clientContextTakeover);
    }

    private readonly \DeflateContext $deflate;

    private readonly \InflateContext $inflate;

    private readonly int $sendingFlushMode;

    private readonly int $receivingFlushMode;

    private function __construct(
        int $receivingWindowSize,
        int $sendingWindowSize,
        bool $receivingContextTakeover,
        bool $sendingContextTakeover
    ) {
        $this->receivingFlushMode = $receivingContextTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FULL_FLUSH;
        $this->sendingFlushMode = $sendingContextTakeover ? \ZLIB_SYNC_FLUSH : \ZLIB_FULL_FLUSH;

        \set_error_handler(self::$initErrorHandler ??= static function (int $code, string $message): never {
            throw new \RuntimeException('Failed to initialized compression context: ' . $message);
        });

        try {
            /** @psalm-suppress InvalidPropertyAssignmentValue Psalm stubs are outdated */
            $this->inflate = \inflate_init(\ZLIB_ENCODING_RAW, ['window' => $receivingWindowSize]);
            /** @psalm-suppress InvalidPropertyAssignmentValue Psalm stubs are outdated */
            $this->deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => $sendingWindowSize]);
        } finally {
            \restore_error_handler();
        }
    }

    public function getRsv(): int
    {
        return self::RSV;
    }

    public function getCompressionThreshold(): int
    {
        return self::MINIMUM_LENGTH;
    }

    public function decompress(string $data, bool $isFinal): ?string
    {
        if ($isFinal) {
            $data .= self::EMPTY_BLOCK;
        }

        \set_error_handler(self::$inflateErrorHandler ??= static fn () => true);

        try {
            /** @psalm-suppress InvalidArgument Psalm stubs are outdated */
            $data = \inflate_add($this->inflate, $data, $this->receivingFlushMode);
        } finally {
            \restore_error_handler();
        }

        if ($data === false) {
            return null;
        }

        return $data;
    }

    public function compress(string $data, bool $isFinal): string
    {
        \set_error_handler(self::$deflateErrorHandler ??= static function (int $code, string $message): never {
            throw new \RuntimeException('Error when compressing data: ' . $message, $code);
        });

        try {
            /** @psalm-suppress InvalidArgument Psalm stubs are outdated */
            $data = \deflate_add($this->deflate, $data, $this->sendingFlushMode);
        } finally {
            \restore_error_handler();
        }

        if ($isFinal && \substr($data, -4) === self::EMPTY_BLOCK) {
            $data = \substr($data, 0, -4);
        }

        return $data;
    }
}
