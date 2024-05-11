<?php declare(strict_types=1);

namespace Amp\Ipc\Sync;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use SplQueue;

/**
 * An asynchronous channel for sending data between threads and processes.
 *
 * Supports full duplex read and write.
 */
final class ChannelledSocket implements Channel
{
    private SplQueue $received;

    private ChannelParser $parser;

    private bool $closed = false;

    /**
     * Creates a new channel from the given stream objects. Note that $read and $write can be the same object.
     *
     */
    public function __construct(private ReadableStream $read, private WritableStream $write)
    {
        $this->received = new \SplQueue;
        $this->parser = new ChannelParser($this->received->push(...));
    }

    /**
     * {@inheritdoc}
     */
    public function send(mixed $data): void
    {
        if ($this->closed) {
            throw new ChannelException('The channel was already closed!');
        }
        try {
            $this->write->write($this->parser->encode($data));
        } catch (StreamException $exception) {
            throw new ChannelException("Sending on the channel failed. Did the context die?", 0, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): mixed
    {
        while ($this->received->isEmpty()) {
            try {
                if ($this->closed) {
                    return null;
                }
                $chunk = $this->read->read();
            } catch (StreamException $exception) {
                throw new ChannelException("Reading from the channel failed. Did the context die?", 0, $exception);
            }

            if ($chunk === null) {
                $this->disconnect();
                return null;
            }

            $this->parser->push($chunk);
        }

        $received = $this->received->shift();
        if ($received instanceof ChannelCloseReq) {
            $this->disconnect();
            return null;
        }
        return $received;
    }

    /**
     * Cleanly disconnect from other endpoint.
     */
    public function disconnect(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            $this->write->write($this->parser->encode(new ChannelCloseReq));
        } catch (\Throwable) {
        }
        $this->read->close();
        $this->write->close();
    }
}
