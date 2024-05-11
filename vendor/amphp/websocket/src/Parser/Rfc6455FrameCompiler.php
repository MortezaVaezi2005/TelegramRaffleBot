<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Websocket\Compression\WebsocketCompressionContext;

final class Rfc6455FrameCompiler implements WebsocketFrameCompiler
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?WebsocketFrameType $currentFrameType = null;

    private bool $compressPayload = false;

    public function __construct(
        private readonly bool $masked,
        private readonly ?WebsocketCompressionContext $compressionContext = null,
    ) {
    }

    public function compileFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): string
    {
        \assert($this->assertState($frameType));

        if ($this->compressionContext && $frameType === WebsocketFrameType::Text) {
            $this->compressPayload = !$isFinal || \strlen($data) > $this->compressionContext->getCompressionThreshold();
        }

        $this->currentFrameType = match ($frameType) {
            WebsocketFrameType::Text, WebsocketFrameType::Binary => $frameType,
            default => $this->currentFrameType,
        };

        $rsv = 0;

        try {
            if ($this->compressionContext && $this->compressPayload && match ($frameType) {
                WebsocketFrameType::Text, WebsocketFrameType::Continuation => true,
                default => false,
            }) {
                if ($frameType !== WebsocketFrameType::Continuation) {
                    $rsv |= $this->compressionContext->getRsv();
                }

                $data = $this->compressionContext->compress($data, $isFinal);
            }
        } catch (\Throwable $exception) {
            $isFinal = true; // Reset state in finally.
            throw $exception;
        } finally {
            if ($isFinal) {
                $this->currentFrameType = null;
                $this->compressPayload = false;
            }
        }

        $length = \strlen($data);
        $w = \chr(((int) $isFinal << 7) | ($rsv << 4) | $frameType->value);

        $maskFlag = $this->masked ? 0x80 : 0;

        if ($length > 0xFFFF) {
            $w .= \chr(0x7F | $maskFlag) . \pack('J', $length);
        } elseif ($length > 0x7D) {
            $w .= \chr(0x7E | $maskFlag) . \pack('n', $length);
        } else {
            $w .= \chr($length | $maskFlag);
        }

        if ($this->masked) {
            $mask = \random_bytes(4);
            return $w . $mask . ($data ^ \str_repeat($mask, ($length + 3) >> 2));
        }

        return $w . $data;
    }

    private function assertState(WebsocketFrameType $frameType): bool
    {
        if ($this->currentFrameType && match ($frameType) {
            WebsocketFrameType::Text, WebsocketFrameType::Binary => true,
            default => false,
        }) {
            throw new \ValueError(\sprintf(
                'Next frame must be a continuation or control frame; got %s (0x%d) while sending %s (0x%d)',
                $frameType->name,
                \dechex($frameType->value),
                $this->currentFrameType->name,
                \dechex($frameType->value),
            ));
        }

        if (!$this->currentFrameType && $frameType === WebsocketFrameType::Continuation) {
            throw new \ValueError('Cannot send a continuation frame without sending a non-final text or binary frame');
        }

        return true;
    }
}
