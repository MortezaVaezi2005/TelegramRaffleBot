<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parser\Parser;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\WebsocketCloseCode;

final class Rfc6455Parser implements WebsocketParser
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_TEXT_ONLY = false;
    public const DEFAULT_VALIDATE_UTF8 = true;
    public const DEFAULT_MESSAGE_SIZE_LIMIT = (2 ** 20) * 10; // 10MB
    public const DEFAULT_FRAME_SIZE_LIMIT = 2 ** 20; // 1MB

    private readonly Parser $parser;

    public function __construct(
        WebsocketFrameHandler $frameHandler,
        private readonly bool $masked,
        private readonly ?WebsocketCompressionContext $compressionContext = null,
        bool $textOnly = self::DEFAULT_TEXT_ONLY,
        bool $validateUtf8 = self::DEFAULT_VALIDATE_UTF8,
        int $messageSizeLimit = self::DEFAULT_MESSAGE_SIZE_LIMIT,
        int $frameSizeLimit = self::DEFAULT_FRAME_SIZE_LIMIT,
    ) {
        $this->parser = new Parser(self::parse(
            $frameHandler,
            $masked,
            $compressionContext,
            $textOnly,
            $validateUtf8,
            $messageSizeLimit,
            $frameSizeLimit,
        ));
    }

    public function push(string $data): void
    {
        $this->parser->push($data);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    /**
     * A stateful generator websocket frame parser.
     *
     * @return \Generator<int, int, string, void>
     *
     * @psalm-suppress InvalidReturnType Psalm infers "never" as the return type.
     */
    private static function parse(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?WebsocketCompressionContext $compressionContext,
        bool $textOnly,
        bool $validateUtf8,
        int $messageSizeLimit,
        int $frameSizeLimit,
    ): \Generator {
        $doUtf8Validation = $validateUtf8;
        $compressedFlag = $compressionContext?->getRsv() ?? 0;

        $dataMsgBytesRecd = 0;
        $savedBuffer = '';
        $compressed = false;

        while (true) {
            $payload = ''; // Free memory from last frame payload.

            $buffer = yield 2;

            $firstByte = \ord($buffer[0]);
            $secondByte = \ord($buffer[1]);

            $final = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4;
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = '';
            $frameLength = $secondByte & 0b01111111;

            if ($opcode >= 3 && $opcode <= 7) {
                throw new WebsocketParserException(WebsocketCloseCode::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
            }

            if ($opcode >= 11 && $opcode <= 15) {
                throw new WebsocketParserException(WebsocketCloseCode::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
            }

            $frameType = WebsocketFrameType::tryFrom($opcode);
            if (!$frameType) {
                throw new WebsocketParserException(WebsocketCloseCode::PROTOCOL_ERROR, 'Invalid opcode');
            }

            $isControlFrame = $frameType->isControlFrame();

            if ($isControlFrame || $frameType === WebsocketFrameType::Continuation) { // Control and continuation frames
                if ($rsv !== 0) {
                    throw new WebsocketParserException(
                        WebsocketCloseCode::PROTOCOL_ERROR,
                        'RSV must be 0 for control or continuation frames',
                    );
                }
            } else { // Text and binary frames
                if ($rsv !== 0 && (!$compressionContext || $rsv & ~$compressedFlag)) {
                    throw new WebsocketParserException(
                        WebsocketCloseCode::PROTOCOL_ERROR,
                        'Invalid RSV value for negotiated extensions',
                    );
                }

                $doUtf8Validation = $validateUtf8 && $frameType === WebsocketFrameType::Text;
                $compressed = (bool) ($rsv & $compressedFlag);
            }

            if ($frameLength === 0x7E) {
                [, $frameLength] = \unpack('n', yield 2);
            } elseif ($frameLength === 0x7F) {
                [, $highBytes, $lowBytes] = \unpack('N2', yield 8);

                if (\PHP_INT_MAX === 0x7fffffff) {
                    if ($highBytes !== 0 || $lowBytes < 0) {
                        throw new WebsocketParserException(
                            WebsocketCloseCode::MESSAGE_TOO_LARGE,
                            'Received payload exceeds maximum allowable size'
                        );
                    }
                    $frameLength = $lowBytes;
                } else {
                    $frameLength = ($highBytes << 32) | $lowBytes;
                    if ($frameLength < 0) {
                        throw new WebsocketParserException(
                            WebsocketCloseCode::PROTOCOL_ERROR,
                            'Most significant bit of 64-bit length field set'
                        );
                    }
                }
            }

            if ($frameLength > 0 && $isMasked === $masked) {
                throw new WebsocketParserException(
                    WebsocketCloseCode::PROTOCOL_ERROR,
                    'Payload mask error'
                );
            }

            if ($isControlFrame) {
                if (!$final) {
                    throw new WebsocketParserException(
                        WebsocketCloseCode::PROTOCOL_ERROR,
                        'Illegal control frame fragmentation'
                    );
                }

                if ($frameLength > 125) {
                    throw new WebsocketParserException(
                        WebsocketCloseCode::PROTOCOL_ERROR,
                        'Control frame payload must be of maximum 125 bytes or less'
                    );
                }
            }

            if ($frameSizeLimit && $frameLength > $frameSizeLimit) {
                throw new WebsocketParserException(
                    WebsocketCloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
            }

            if ($messageSizeLimit && ($frameLength + $dataMsgBytesRecd) > $messageSizeLimit) {
                throw new WebsocketParserException(
                    WebsocketCloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
            }

            if ($isMasked) {
                $maskingKey = yield 4;
            }

            if ($frameLength) {
                $payload = yield $frameLength;
            }

            if ($isMasked) {
                // This is memory hungry, but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                /** @psalm-suppress InvalidOperand String operands expected. */
                $payload ^= \str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($isControlFrame) {
                $frameHandler->handleFrame($frameType, $payload, true);
                continue;
            }

            if ($textOnly && $frameType === WebsocketFrameType::Binary) {
                throw new WebsocketParserException(
                    WebsocketCloseCode::UNACCEPTABLE_TYPE,
                    'BINARY opcodes (0x02) not accepted'
                );
            }

            $dataMsgBytesRecd += $frameLength;

            if ($savedBuffer !== '') {
                $payload = $savedBuffer . $payload;
                $savedBuffer = '';
            }

            if ($compressed) {
                /** @psalm-suppress PossiblyNullReference */
                $payload = $compressionContext->decompress($payload, $final);

                if ($payload === null) { // Decompression failed.
                    throw new WebsocketParserException(
                        WebsocketCloseCode::PROTOCOL_ERROR,
                        'Invalid compressed data'
                    );
                }
            }

            if ($doUtf8Validation) {
                if ($final) {
                    $valid = \preg_match('//u', $payload);
                } else {
                    for ($i = 0; !($valid = \preg_match('//u', $payload)); $i++) {
                        $savedBuffer = \substr($payload, -1) . $savedBuffer;
                        $payload = \substr($payload, 0, -1);

                        if ($i === 3) { // Remove a maximum of three bytes
                            break;
                        }
                    }
                }

                /** @psalm-suppress PossiblyUndefinedVariable Defined in either condition above. */
                if (!$valid) {
                    throw new WebsocketParserException(
                        WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE,
                        'Invalid TEXT data; UTF-8 required'
                    );
                }
            }

            if ($final) {
                $dataMsgBytesRecd = 0;
            }

            $frameHandler->handleFrame($frameType, $payload, $final);
        }
    }
}
