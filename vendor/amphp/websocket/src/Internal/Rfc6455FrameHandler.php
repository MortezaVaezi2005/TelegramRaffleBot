<?php declare(strict_types=1);

namespace Amp\Websocket\Internal;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Amp\Websocket\Parser\WebsocketFrameCompiler;
use Amp\Websocket\Parser\WebsocketFrameHandler;
use Amp\Websocket\Parser\WebsocketFrameType;
use Amp\Websocket\Parser\WebsocketParser;
use Amp\Websocket\Parser\WebsocketParserException;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketClosedException;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketHeartbeatQueue;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketRateLimit;
use function Amp\async;

/** @internal */
final class Rfc6455FrameHandler implements WebsocketFrameHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?DeferredFuture $closeDeferred;

    /** @var Queue<WebsocketMessage> */
    private readonly Queue $messageQueue;

    /** @var Queue<string>|null */
    private ?Queue $currentMessageQueue = null;

    public function __construct(
        private readonly Socket $socket,
        private readonly WebsocketFrameCompiler $frameCompiler,
        private readonly ?WebsocketHeartbeatQueue $heartbeatQueue,
        private readonly ?WebsocketRateLimit $rateLimit,
        private readonly WebsocketClientMetadata $metadata,
        private readonly float $closePeriod,
    ) {
        $this->closeDeferred = new DeferredFuture();
        $this->messageQueue = new Queue();
    }

    /**
     * @return ConcurrentIterator<WebsocketMessage>
     */
    public function iterate(): ConcurrentIterator
    {
        return $this->messageQueue->iterate();
    }

    public function read(WebsocketParser $parser): void
    {
        try {
            while (($chunk = $this->socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $this->metadata->lastReadAt = \microtime(true);
                $this->metadata->bytesReceived += \strlen($chunk);

                $this->heartbeatQueue?->update($this->metadata->id);
                $this->rateLimit?->notifyBytesReceived($this->metadata->id, \strlen($chunk));

                $parser->push($chunk);

                $chunk = ''; // Free memory from last chunk read.
            }
        } catch (WebsocketParserException $exception) {
            $message = $exception->getMessage();
            $code = $exception->getCode();
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        } finally {
            $parser->cancel();
            $this->heartbeatQueue?->remove($this->metadata->id);
        }

        $this->closeDeferred?->complete();
        $this->closeDeferred = null;

        if (!$this->metadata->isClosed()) {
            $this->close(
                $code ?? WebsocketCloseCode::ABNORMAL_CLOSE,
                $message ?? 'TCP connection closed unexpectedly',
                byPeer: true,
            );
        }
    }

    public function handleFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): void
    {
        ++$this->metadata->framesReceived;
        $this->rateLimit?->notifyFramesReceived($this->metadata->id, 1);

        if ($frameType->isControlFrame()) {
            $this->onControlFrame($frameType, $data);
        } else {
            $this->onData($frameType, $data, $isFinal);
        }
    }

    private function onData(WebsocketFrameType $frameType, string $data, bool $terminated): void
    {
        \assert(!$frameType->isControlFrame());

        $this->metadata->lastDataReadAt = \microtime(true);

        // Ignore further data received after initiating close.
        if ($this->metadata->isClosed()) {
            return;
        }

        if (!$this->currentMessageQueue) {
            if ($frameType === WebsocketFrameType::Continuation) {
                $this->close(
                    WebsocketCloseCode::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY',
                );
                return;
            }

            ++$this->metadata->messagesReceived;

            if (!$terminated) {
                $this->currentMessageQueue = new Queue();
            }

            // Avoid holding a reference to the ReadableStream or Message object here so destructors will be invoked
            // if the message is not consumed by the user.
            $this->messageQueue->push(self::createMessage(
                $frameType,
                $this->currentMessageQueue
                    ? new ReadableIterableStream($this->currentMessageQueue->iterate())
                    : $data,
            ));

            if (!$this->currentMessageQueue) {
                return;
            }
        } elseif ($frameType !== WebsocketFrameType::Continuation) {
            $this->close(
                WebsocketCloseCode::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION',
            );
            return;
        }

        try {
            $this->currentMessageQueue->push($data);
        } catch (DisposedException) {
            // Message disposed, ignore exception.
        }

        if ($terminated) {
            $this->currentMessageQueue->complete();
            $this->currentMessageQueue = null;
        }
    }

    private function onControlFrame(WebsocketFrameType $frameType, string $data): void
    {
        \assert($frameType->isControlFrame());

        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->isClosed() && $this->closeDeferred === null) {
            return;
        }

        switch ($frameType) {
            case WebsocketFrameType::Close:
                $this->closeDeferred?->complete();
                $this->closeDeferred = null;

                if ($this->metadata->isClosed()) {
                    break;
                }

                $length = \strlen($data);
                if ($length === 0) {
                    $code = WebsocketCloseCode::NONE;
                    $reason = '';
                } elseif ($length < 2) {
                    $code = WebsocketCloseCode::PROTOCOL_ERROR;
                    $reason = 'Close code must be two bytes';
                } else {
                    $code = \unpack('n', $data)[1];
                    $reason = \substr($data, 2);

                    if ($code < 1000 // Reserved and unused.
                        || ($code >= 1004 && $code <= 1006) // Should not be sent over wire
                        || ($code >= 1014 && $code <= 1015) // Should not be sent over wire
                        || ($code >= 1016 && $code <= 1999) // Disallowed, reserved for future use
                        || ($code >= 2000 && $code <= 2999) // Disallowed, reserved for Websocket extensions
                        // 3000-3999 allowed, reserved for libraries
                        // 4000-4999 allowed, reserved for applications
                        || $code >= 5000 // >= 5000 invalid
                    ) {
                        $code = WebsocketCloseCode::PROTOCOL_ERROR;
                        $reason = 'Invalid close code';
                    } elseif (!\preg_match('//u', $reason)) {
                        $code = WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE;
                        $reason = 'Close reason must be valid UTF-8';
                    }
                }

                $this->close($code, $reason, byPeer: true);
                break;

            case WebsocketFrameType::Ping:
                ++$this->metadata->pingsReceived;
                $this->write(WebsocketFrameType::Pong, $data);
                ++$this->metadata->pongsSent;
                break;

            case WebsocketFrameType::Pong:
                if (!\preg_match('/^[1-9][0-9]*$/', $data)) {
                    // Ignore pong payload that is not an integer.
                    break;
                }

                // We need a min() here, else someone might just send a pong frame with a very high pong count and
                // leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $this->metadata->pongsReceived = \min($this->metadata->pingsSent, \max(0, (int) $data));
                $this->metadata->lastHeartbeatAt = \microtime(true);
                break;

            default:
                // This should be unreachable
                throw new \Error('Non-control frame opcode: ' . $frameType->name);
        }
    }

    public function write(WebsocketFrameType $frameType, string $data, bool $isFinal = true): void
    {
        $frame = $this->frameCompiler->compileFrame($frameType, $data, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = \microtime(true);

        $this->socket->write($frame);
    }

    public function close(int $code, string $reason = '', bool $byPeer = false): void
    {
        if ($this->metadata->isClosed()) {
            return;
        }

        \assert($code !== WebsocketCloseCode::NONE || $reason === '');

        $this->metadata->closeInfo = new WebsocketCloseInfo(
            $code,
            $reason,
            \microtime(true),
            $byPeer,
        );

        $this->messageQueue->complete();

        $this->currentMessageQueue?->error(new WebsocketClosedException(
            'Connection closed while streaming message body',
            $code,
            $reason,
        ));
        $this->currentMessageQueue = null;

        if ($this->socket->isClosed()) {
            return;
        }

        try {
            $cancellation = new TimeoutCancellation($this->closePeriod);

            async(
                $this->write(...),
                WebsocketFrameType::Close,
                $code !== WebsocketCloseCode::NONE ? \pack('n', $code) . $reason : '',
            )->await($cancellation);

            // Wait for peer close frame for configured number of seconds.
            $this->closeDeferred?->getFuture()->await($cancellation);
        } catch (\Throwable) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
    }

    /**
     * @param \Closure(int, WebsocketCloseInfo):void $onClose
     */
    public function onClose(\Closure $onClose): void
    {
        $future = $this->closeDeferred?->getFuture() ?? Future::complete();

        $metadata = $this->metadata;
        $future->finally(static function () use ($onClose, $metadata): void {
            \assert($metadata->closeInfo !== null, 'Client was not closed when onClose invoked');
            $onClose($metadata->id, $metadata->closeInfo);
        });
    }

    private static function createMessage(WebsocketFrameType $frameType, ReadableStream|string $stream): WebsocketMessage
    {
        if ($frameType === WebsocketFrameType::Binary) {
            return WebsocketMessage::fromBinary($stream);
        }

        return WebsocketMessage::fromText($stream);
    }
}
