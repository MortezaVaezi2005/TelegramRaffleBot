#!/usr/bin/env php
<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\Future;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresNotification;
use function Amp\async;
use function Amp\delay;

$config = PostgresConfig::fromString('host=localhost user=postgres');
$pool = new PostgresConnectionPool($config);

$channel1 = "test1";
$channel2 = "test2";

$listener1 = $pool->listen($channel1);

printf("Listening on channel '%s'\n", $listener1->getChannel());

$listener2 = $pool->listen($channel2);

printf("Listening on channel '%s'\n", $listener2->getChannel());

async(function () use ($pool, $listener1, $listener2, $channel1, $channel2): void {
    $pool->notify($channel1, "Data 1.1");

    delay(0.5);

    $pool->notify($channel2, "Data 2.1");

    delay(0.5);

    $pool->notify($channel2, "Data 2.2");

    delay(0.5);

    printf("Unlistening from channel '%s'\n", $listener2->getChannel());
    $listener2->unlisten();

    delay(0.5);

    $pool->notify($channel1, "Data 1.2");

    delay(0.5);

    printf("Unlistening from channel '%s'\n", $listener1->getChannel());
    $listener1->unlisten();
});

$consumer = function (PostgresListener $listener): void {
    /** @var PostgresNotification $notification */
    foreach ($listener as $notification) {
        printf(
            "Received notification from PID %d on channel '%s' with payload: %s\n",
            $notification->pid,
            $notification->channel,
            $notification->payload,
        );
    }
};

$future1 = async($consumer, $listener1);
$future2 = async($consumer, $listener2);

Future\await([$future1, $future2]);

$pool->close();
