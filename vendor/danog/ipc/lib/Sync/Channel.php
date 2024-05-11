<?php declare(strict_types=1);

namespace Amp\Ipc\Sync;

/**
 * Interface for sending messages between execution contexts.
 */
interface Channel
{
    /**
     * @throws \Amp\Ipc\Sync\SynchronizationError If the context has not been started or the context
     *     unexpectedly ends.
     * @throws \Amp\Ipc\Sync\ChannelException If receiving from the channel fails.
     * @throws \Amp\Ipc\Sync\SerializationException If unserializing the data fails.
     */
    public function receive(): mixed;

    /**
     * @throws \Amp\Ipc\Sync\SynchronizationError If the context has not been started or the context
     *     unexpectedly ends.
     * @throws \Amp\Ipc\Sync\ChannelException If sending on the channel fails.
     * @throws \Error If an ExitResult object is given.
     * @throws \Amp\Ipc\Sync\SerializationException If serializing the data fails.
     */
    public function send(mixed $data): void;
}
