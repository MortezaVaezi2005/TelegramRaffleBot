#!/usr/bin/env php
<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use function Amp\async;
use function Amp\delay;

$config = PostgresConfig::fromString('host=localhost user=postgres');
$pool = new PostgresConnectionPool($config);

$channel = "test";

$listener = $pool->listen($channel);

printf("Listening on channel '%s'\n", $listener->getChannel());

async(function () use ($pool, $channel, $listener): void {
    delay(1);

    $pool->notify($channel, "Data 1"); // Send first notification.

    delay(1);

    $pool->notify($channel, "Data 2"); // Send second notification.

    delay(1);

    $listener->unlisten();
});

foreach ($listener as $notification) {
    printf(
        "Received notification from PID %d on channel '%s' with payload: %s\n",
        $notification->pid,
        $notification->channel,
        $notification->payload,
    );
}

$pool->close();
