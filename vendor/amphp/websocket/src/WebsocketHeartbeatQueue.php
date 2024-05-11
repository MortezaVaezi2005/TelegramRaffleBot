<?php declare(strict_types=1);

namespace Amp\Websocket;

interface WebsocketHeartbeatQueue
{
    /**
     * Insert the given client into the heartbeat queue. If a reference is retained to the {@see WebsocketClient},
     * it is recommended to use {@see \WeakReference}.
     */
    public function insert(WebsocketClient $client): void;

    /**
     * Update the heartbeat interval for the given client.
     */
    public function update(int $clientId): void;

    /**
     * Remove the given client from the heartbeat queue.
     */
    public function remove(int $clientId): void;
}
