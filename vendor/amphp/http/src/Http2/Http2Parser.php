<?php declare(strict_types=1);

namespace Amp\Http\Http2;

use Amp\Http\HPack;
use Amp\Parser\Parser;

final class Http2Parser
{
    public const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    private const DEFAULT_MAX_FRAME_SIZE = 1 << 14;

    private const HEADER_NAME_REGEX = '/^[\x21-\x40\x5b-\x7e]+$/';

    public const KNOWN_RESPONSE_PSEUDO_HEADERS = [
        ":status" => true,
    ];

    public const KNOWN_REQUEST_PSEUDO_HEADERS = [
        ":method" => true,
        ":authority" => true,
        ":path" => true,
        ":scheme" => true,
    ];

    // SETTINGS Flags - https://http2.github.io/http2-spec/#rfc.section.6.5
    public const ACK = 0x01;

    // HEADERS Flags - https://http2.github.io/http2-spec/#rfc.section.6.2
    public const NO_FLAG = 0x00;
    public const END_STREAM = 0x01;
    public const END_HEADERS = 0x04;
    public const PADDED = 0x08;
    public const PRIORITY_FLAG = 0x20;

    // Frame Types - https://http2.github.io/http2-spec/#rfc.section.11.2
    public const DATA = 0x00;
    public const HEADERS = 0x01;
    public const PRIORITY = 0x02;
    public const RST_STREAM = 0x03;
    public const SETTINGS = 0x04;
    public const PUSH_PROMISE = 0x05;
    public const PING = 0x06;
    public const GOAWAY = 0x07;
    public const WINDOW_UPDATE = 0x08;
    public const CONTINUATION = 0x09;

    // Settings
    public const HEADER_TABLE_SIZE = 0x1; // 1 << 12
    public const ENABLE_PUSH = 0x2; // 1
    public const MAX_CONCURRENT_STREAMS = 0x3; // INF
    public const INITIAL_WINDOW_SIZE = 0x4; // 1 << 16 - 1
    public const MAX_FRAME_SIZE = 0x5; // 1 << 14
    public const MAX_HEADER_LIST_SIZE = 0x6; // INF

    // Error codes
    public const GRACEFUL_SHUTDOWN = 0x0;
    public const PROTOCOL_ERROR = 0x1;
    public const INTERNAL_ERROR = 0x2;
    public const FLOW_CONTROL_ERROR = 0x3;
    public const SETTINGS_TIMEOUT = 0x4;
    public const STREAM_CLOSED = 0x5;
    public const FRAME_SIZE_ERROR = 0x6;
    public const REFUSED_STREAM = 0x7;
    public const CANCEL = 0x8;
    public const COMPRESSION_ERROR = 0x9;
    public const CONNECT_ERROR = 0xa;
    public const ENHANCE_YOUR_CALM = 0xb;
    public const INADEQUATE_SECURITY = 0xc;
    public const HTTP_1_1_REQUIRED = 0xd;

    public static function compileFrame(string $data, int $type, int $flags, int $stream = 0): string
    {
        \assert(Http2Parser::logDebugFrame('send', $type, $flags, $stream, \strlen($data)));
        return \pack("NcN", (\strlen($data) << 8) | ($type & 0xff), $flags, $stream) . $data;
    }

    public static function getFrameName(int $type): string
    {
        $names = [
            self::DATA => 'DATA',
            self::HEADERS => 'HEADERS',
            self::PRIORITY => 'PRIORITY',
            self::RST_STREAM => 'RST_STREAM',
            self::SETTINGS => 'SETTINGS',
            self::PUSH_PROMISE => 'PUSH_PROMISE',
            self::PING => 'PING',
            self::GOAWAY => 'GOAWAY',
            self::WINDOW_UPDATE => 'WINDOW_UPDATE',
            self::CONTINUATION => 'CONTINUATION',
        ];

        return $names[$type] ?? ('0x' . \bin2hex(\chr($type)));
    }

    private static function logDebugFrame(
        string $action,
        int $frameType,
        int $frameFlags,
        int $streamId,
        int $frameLength
    ): bool {
        /** @psalm-suppress RiskyTruthyFalsyComparison */
        $env = \getenv("AMP_DEBUG_HTTP2_FRAMES") ?: "0";
        if (match ($env) {
            "0", "false", "off" => false,
            default => $env || \defined("AMP_DEBUG_HTTP2_FRAMES") && \AMP_DEBUG_HTTP2_FRAMES,
        }) {
            \fwrite(\STDERR, \sprintf(
                "%s %s <flags = %s, stream = %d, length = %d>\r\n",
                $action,
                self::getFrameName($frameType),
                \bin2hex(\chr($frameFlags)),
                $streamId,
                $frameLength,
            ));
        }

        return true;
    }

    private bool $continuationExpected = false;

    private int $headerFrameType = 0;

    /** @var list<string> */
    private array $headerBuffer = [];

    private int $headerLength = 0;

    private int $headerStream = 0;

    private int $receivedFrameCount = 0;

    private int $receivedByteCount = 0;

    private readonly Parser $parser;

    /**
     * @param positive-int $headerSizeLimit
     * @param positive-int $frameSizeLimit
     */
    public function __construct(
        private readonly Http2Processor $handler,
        private readonly HPack $hpack,
        ?string $peerSettings = null,
        private readonly int $headerSizeLimit = self::DEFAULT_MAX_FRAME_SIZE,
        private readonly int $frameSizeLimit = self::DEFAULT_MAX_FRAME_SIZE,
    ) {
        $this->parser = new Parser($this->parse($peerSettings));
    }

    public function getReceivedByteCount(): int
    {
        return $this->receivedByteCount;
    }

    public function getReceivedFrameCount(): int
    {
        return $this->receivedFrameCount;
    }

    /**
     * @throws Http2ConnectionException
     */
    public function push(string $data): void
    {
        $this->parser->push($data);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    private function parse(?string $settings = null): \Generator
    {
        if ($settings !== null) {
            $this->parseSettings($settings, \strlen($settings), self::NO_FLAG, 0);
        }

        while (true) {
            /** @var string $frameHeader */
            $frameHeader = yield 9;
            $this->receivedByteCount += 9;

            [
                'length' => $frameLength,
                'flags' => $frameFlags,
                'id' => $streamId,
            ] = \unpack('Nlength/cflags/Nid', $frameHeader);

            $frameType = $frameLength & 0xff;
            $frameLength >>= 8;

            $streamId &= 0x7fffffff;

            $frameBuffer = $frameLength === 0 ? '' : yield $frameLength;
            $this->receivedByteCount += $frameLength;

            $this->receivedFrameCount++;

            \assert(self::logDebugFrame('recv', $frameType, $frameFlags, $streamId, $frameLength));

            try {
                // Do we want to allow increasing the maximum frame size?
                if ($frameLength > $this->frameSizeLimit) {
                    throw new Http2ConnectionException("Frame size limit exceeded", self::FRAME_SIZE_ERROR);
                }

                if ($this->continuationExpected && $frameType !== self::CONTINUATION) {
                    throw new Http2ConnectionException("Expected continuation frame", self::PROTOCOL_ERROR);
                }

                match ($frameType) {
                    self::DATA => $this->parseDataFrame($frameBuffer, $frameLength, $frameFlags, $streamId),
                    self::PUSH_PROMISE => $this->parsePushPromise($frameBuffer, $frameLength, $frameFlags, $streamId),
                    self::HEADERS => $this->parseHeaders($frameBuffer, $frameLength, $frameFlags, $streamId),
                    self::PRIORITY => $this->parsePriorityFrame($frameBuffer, $frameLength, $streamId),
                    self::RST_STREAM => $this->parseStreamReset($frameBuffer, $frameLength, $streamId),
                    self::SETTINGS => $this->parseSettings($frameBuffer, $frameLength, $frameFlags, $streamId),
                    self::PING => $this->parsePing($frameBuffer, $frameLength, $frameFlags, $streamId),
                    self::GOAWAY => $this->parseGoAway($frameBuffer, $frameLength, $streamId),
                    self::WINDOW_UPDATE => $this->parseWindowUpdate($frameBuffer, $frameLength, $streamId),
                    self::CONTINUATION => $this->parseContinuation($frameBuffer, $frameFlags, $streamId),
                    default => null, // Ignore and discard unknown frame per spec
                };
            } catch (Http2StreamException $exception) {
                $this->handler->handleStreamException($exception);
            } catch (Http2ConnectionException $exception) {
                $this->handler->handleConnectionException($exception);

                throw $exception;
            }
        }
    }

    private function parseDataFrame(string $frameBuffer, int $frameLength, int $frameFlags, int $streamId): void
    {
        $isPadded = $frameFlags & self::PADDED;

        $headerLength = $isPadded ? 1 : 0;

        if ($frameLength < $headerLength) {
            $this->throwInvalidFrameSizeError();
        }

        $header = $headerLength === 0 ? '' : \substr($frameBuffer, 0, $headerLength);

        $padding = $isPadded ? \ord($header[0]) : 0;

        if ($streamId === 0) {
            $this->throwInvalidZeroStreamIdError();
        }

        if ($frameLength - $headerLength - $padding < 0) {
            $this->throwInvalidPaddingError();
        }

        $data = \substr($frameBuffer, $headerLength, $frameLength - $headerLength - $padding);

        $this->handler->handleData($streamId, $data);

        if ($frameFlags & self::END_STREAM) {
            $this->handler->handleStreamEnd($streamId);
        }
    }

    /** @see https://http2.github.io/http2-spec/#rfc.section.6.6 */
    private function parsePushPromise(string $frameBuffer, int $frameLength, int $frameFlags, int $streamId): void
    {
        $isPadded = $frameFlags & self::PADDED;

        $headerLength = $isPadded ? 5 : 4;

        if ($frameLength < $headerLength) {
            $this->throwInvalidFrameSizeError();
        }

        $header = \substr($frameBuffer, 0, $headerLength);

        $padding = $isPadded ? \ord($header[0]) : 0;

        $pushId = \unpack("N", $header)[1] & 0x7fffffff;

        if ($frameLength - $headerLength - $padding < 0) {
            $this->throwInvalidPaddingError();
        }

        $this->headerFrameType = self::PUSH_PROMISE;

        $this->pushHeaderBlockFragment(
            $pushId,
            \substr($frameBuffer, $headerLength, $frameLength - $headerLength - $padding)
        );

        if ($frameFlags & self::END_HEADERS) {
            $this->continuationExpected = false;

            [$pseudo, $headers] = $this->parseHeaderBuffer();

            $this->handler->handlePushPromise($streamId, $pushId, $pseudo, $headers);
        } else {
            $this->continuationExpected = true;
        }

        if ($frameFlags & self::END_STREAM) {
            $this->handler->handleStreamEnd($streamId);
        }
    }

    /**
     * @return array{array<string, string>, array<string, list<string>>}
     */
    private function parseHeaderBuffer(): array
    {
        if ($this->headerStream === 0) {
            throw new Http2ConnectionException('Invalid stream ID 0 for header block', self::PROTOCOL_ERROR);
        }

        if (!$this->headerBuffer) {
            throw new Http2StreamException('Invalid empty header section', $this->headerStream, self::PROTOCOL_ERROR);
        }

        /** @var list<array{string, string}>|null $decoded */
        $decoded = $this->hpack->decode(\implode($this->headerBuffer), $this->headerSizeLimit);

        if ($decoded === null) {
            throw new Http2ConnectionException("Compression error in headers", self::COMPRESSION_ERROR);
        }

        $headers = [];
        $pseudo = [];

        foreach ($decoded as [$name, $value]) {
            if (!\preg_match(self::HEADER_NAME_REGEX, $name)) {
                throw new Http2StreamException("Invalid header field name", $this->headerStream, self::PROTOCOL_ERROR);
            }

            if ($name[0] === ':') {
                if (!empty($headers)) {
                    throw new Http2ConnectionException(
                        "Pseudo header after other headers",
                        self::PROTOCOL_ERROR
                    );
                }

                if (isset($pseudo[$name])) {
                    throw new Http2ConnectionException(
                        "Repeat pseudo header",
                        self::PROTOCOL_ERROR
                    );
                }

                $pseudo[$name] = $value;
                continue;
            }

            $headers[$name][] = $value;
        }

        $this->headerBuffer = [];
        $this->headerLength = 0;
        $this->headerStream = 0;

        return [$pseudo, $headers];
    }

    private function pushHeaderBlockFragment(int $streamId, string $buffer): void
    {
        if ($this->headerStream !== 0 && $this->headerStream !== $streamId) {
            throw new Http2ConnectionException(
                "Expected CONTINUATION frame for stream ID " . $this->headerStream,
                self::PROTOCOL_ERROR
            );
        }

        $this->headerStream = $streamId;
        $this->headerBuffer[] = $buffer;
        $this->headerLength += \strlen($buffer);

        $headersTooLarge = $this->headerLength > $this->headerSizeLimit;

        if ($headersTooLarge) {
            throw new Http2ConnectionException(
                "Headers exceed the maximum configured size of {$this->headerSizeLimit} bytes",
                self::COMPRESSION_ERROR
            );
        }
    }

    /** @see https://http2.github.io/http2-spec/#HEADERS */
    private function parseHeaders(string $frameBuffer, int $frameLength, int $frameFlags, int $streamId): void
    {
        if ($streamId === 0) {
            $this->throwInvalidZeroStreamIdError();
        }

        $headerLength = 0;
        $isPadded = $frameFlags & self::PADDED;
        $isPriority = $frameFlags & self::PRIORITY_FLAG;

        if ($isPadded) {
            $headerLength++;
        }

        if ($isPriority) {
            $headerLength += 5;
        }

        if ($frameLength < $headerLength) {
            $this->throwInvalidFrameSizeError();
        }

        $header = \substr($frameBuffer, 0, $headerLength);

        $padding = $isPadded ? \ord($header[0]) : 0;

        if ($isPriority) {
            ['parent' => $parent, 'weight' => $weight] = \unpack("Nparent/cweight", $header, $isPadded ? 1 : 0);

            $parent &= 0x7fffffff;

            if ($parent === $streamId) {
                $this->throwInvalidRecursiveDependency($streamId);
            }

            $this->handler->handlePriority($streamId, $parent, $weight + 1);
        }

        if ($frameLength - $headerLength - $padding < 0) {
            $this->throwInvalidPaddingError();
        }

        $this->headerFrameType = self::HEADERS;

        $this->pushHeaderBlockFragment(
            $streamId,
            \substr($frameBuffer, $headerLength, $frameLength - $headerLength - $padding)
        );

        $ended = $frameFlags & self::END_STREAM;

        if ($frameFlags & self::END_HEADERS) {
            $this->continuationExpected = false;

            $headersTooLarge = $this->headerLength > $this->headerSizeLimit;

            [$pseudo, $headers] = $this->parseHeaderBuffer();

            // This must happen after the parsing, otherwise we loose the connection state and must close the whole
            // connection, which is not what we want here…
            if ($headersTooLarge) {
                throw new Http2StreamException(
                    "Headers exceed maximum configured size of {$this->headerSizeLimit} bytes",
                    $streamId,
                    self::ENHANCE_YOUR_CALM
                );
            }

            $this->handler->handleHeaders($streamId, $pseudo, $headers, (bool) $ended);
        } else {
            $this->continuationExpected = true;
        }

        if ($ended) {
            $this->handler->handleStreamEnd($streamId);
        }
    }

    private function parsePriorityFrame(string $frameBuffer, int $frameLength, int $streamId): void
    {
        if ($frameLength !== 5) {
            $this->throwInvalidFrameSizeError();
        }

        ['parent' => $parent, 'weight' => $weight] = \unpack("Nparent/cweight", $frameBuffer);

        if ($exclusive = ($parent & 0x80000000)) {
            $parent &= 0x7fffffff;
        }

        if ($streamId === 0) {
            $this->throwInvalidZeroStreamIdError();
        }

        if ($parent === $streamId) {
            $this->throwInvalidRecursiveDependency($streamId);
        }

        $this->handler->handlePriority($streamId, $parent, $weight + 1);
    }

    private function parseStreamReset(string $frameBuffer, int $frameLength, int $streamId): void
    {
        if ($frameLength !== 4) {
            $this->throwInvalidFrameSizeError();
        }

        if ($streamId === 0) {
            $this->throwInvalidZeroStreamIdError();
        }

        $errorCode = \unpack('N', $frameBuffer)[1];

        $this->handler->handleStreamReset($streamId, $errorCode);
    }

    private function parseSettings(string $frameBuffer, int $frameLength, int $frameFlags, int $streamId): void
    {
        if ($streamId !== 0) {
            $this->throwInvalidNonZeroStreamIdError();
        }

        if ($frameFlags & self::ACK) {
            if ($frameLength) {
                $this->throwInvalidFrameSizeError();
            }

            return; // Got ACK, nothing to do
        }

        if ($frameLength % 6 !== 0) {
            $this->throwInvalidFrameSizeError();
        }

        if ($frameLength > 60) {
            // Even with room for a few future options, sending that a big SETTINGS frame is just about
            // wasting our processing time. We declare this a protocol error.
            throw new Http2ConnectionException("Excessive SETTINGS frame", self::PROTOCOL_ERROR);
        }

        $settings = [];

        while ($frameLength > 0) {
            ['key' => $key, 'value' => $value] = \unpack("nkey/Nvalue", $frameBuffer);

            if ($value < 0) {
                throw new Http2ConnectionException(
                    "Invalid setting: {$value}",
                    self::PROTOCOL_ERROR
                );
            }

            $settings[$key] = $value;

            $frameBuffer = \substr($frameBuffer, 6);
            $frameLength -= 6;
        }

        $this->handler->handleSettings($settings);
    }

    /** @see https://http2.github.io/http2-spec/#rfc.section.6.7 */
    private function parsePing(string $frameBuffer, int $frameLength, int $frameFlags, int $streamId): void
    {
        if ($frameLength !== 8) {
            $this->throwInvalidFrameSizeError();
        }

        if ($streamId !== 0) {
            $this->throwInvalidNonZeroStreamIdError();
        }

        if ($frameFlags & self::ACK) {
            $this->handler->handlePong($frameBuffer);
        } else {
            $this->handler->handlePing($frameBuffer);
        }
    }

    /** @see https://http2.github.io/http2-spec/#rfc.section.6.8 */
    private function parseGoAway(string $frameBuffer, int $frameLength, int $streamId): void
    {
        if ($frameLength < 8) {
            $this->throwInvalidFrameSizeError();
        }

        if ($streamId !== 0) {
            $this->throwInvalidNonZeroStreamIdError();
        }

        ['last' => $lastId, 'error' => $error] = \unpack("Nlast/Nerror", $frameBuffer);

        $this->handler->handleShutdown($lastId & 0x7fffffff, $error, \substr($frameBuffer, 8));
    }

    /** @see https://http2.github.io/http2-spec/#rfc.section.6.9 */
    private function parseWindowUpdate(string $frameBuffer, int $frameLength, int $streamId): void
    {
        if ($frameLength !== 4) {
            $this->throwInvalidFrameSizeError();
        }

        $windowSize = \unpack('N', $frameBuffer)[1];

        if ($windowSize === 0) {
            if ($streamId) {
                throw new Http2StreamException(
                    "Invalid zero window update value",
                    $streamId,
                    self::PROTOCOL_ERROR
                );
            }

            throw new Http2ConnectionException("Invalid zero window update value", self::PROTOCOL_ERROR);
        }

        if ($streamId) {
            $this->handler->handleStreamWindowIncrement($streamId, $windowSize);
        } else {
            $this->handler->handleConnectionWindowIncrement($windowSize);
        }
    }

    /** @see https://http2.github.io/http2-spec/#rfc.section.6.10 */
    private function parseContinuation(string $frameBuffer, int $frameFlags, int $streamId): void
    {
        if ($streamId !== $this->headerStream) {
            throw new Http2ConnectionException(
                "Invalid CONTINUATION frame stream ID",
                self::PROTOCOL_ERROR
            );
        }

        if (!$this->headerBuffer) {
            throw new Http2ConnectionException(
                "Unexpected CONTINUATION frame for stream ID " . $this->headerStream,
                self::PROTOCOL_ERROR
            );
        }

        $this->pushHeaderBlockFragment($streamId, $frameBuffer);

        $ended = $frameFlags & self::END_STREAM;

        if ($frameFlags & self::END_HEADERS) {
            $this->continuationExpected = false;

            $isPush = $this->headerFrameType === self::PUSH_PROMISE;
            $pushId = $this->headerStream;

            [$pseudo, $headers] = $this->parseHeaderBuffer();

            if ($isPush) {
                $this->handler->handlePushPromise($streamId, $pushId, $pseudo, $headers);
            } else {
                $this->handler->handleHeaders($streamId, $pseudo, $headers, (bool) $ended);
            }
        }

        if ($ended) {
            $this->handler->handleStreamEnd($streamId);
        }
    }

    private function throwInvalidFrameSizeError(): never
    {
        throw new Http2ConnectionException("Invalid frame length", self::PROTOCOL_ERROR);
    }

    private function throwInvalidRecursiveDependency(int $streamId): never
    {
        throw new Http2ConnectionException(
            "Invalid recursive dependency for stream {$streamId}",
            self::PROTOCOL_ERROR
        );
    }

    private function throwInvalidPaddingError(): never
    {
        throw new Http2ConnectionException("Padding greater than length", self::PROTOCOL_ERROR);
    }

    private function throwInvalidZeroStreamIdError(): never
    {
        throw new Http2ConnectionException("Invalid zero stream ID", self::PROTOCOL_ERROR);
    }

    private function throwInvalidNonZeroStreamIdError(): never
    {
        throw new Http2ConnectionException("Invalid non-zero stream ID", self::PROTOCOL_ERROR);
    }
}
