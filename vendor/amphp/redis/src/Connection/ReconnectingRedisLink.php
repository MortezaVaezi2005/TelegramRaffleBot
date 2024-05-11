<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Redis\Protocol\RedisResponse;
use Amp\Redis\RedisException;
use Revolt\EventLoop;

final class ReconnectingRedisLink implements RedisLink
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \SplQueue<array{DeferredFuture, string, list<string>}> */
    private readonly \SplQueue $queue;

    private ?int $database = null;

    private bool $running = false;

    private ?RedisConnection $connection = null;

    public function __construct(private readonly RedisConnector $connector)
    {
        $this->queue = new \SplQueue();
    }

    public function __destruct()
    {
        $this->running = false;
        $this->connection?->close();
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        if (!$this->running) {
            $this->run();
        }

        $parameters = \array_values(\array_map(\strval(...), $parameters));

        try {
            $response = $this->enqueue($command, $parameters)->await();
        } finally {
            if (\strcasecmp($command, 'quit') === 0) {
                $this->connection?->close();
            }
        }

        if (\strcasecmp($command, 'select') === 0) {
            $this->database = (int) ($parameters[0] ?? 0);
        }

        return $response;
    }

    /**
     * @param list<string> $parameters
     *
     * @return Future<RedisResponse>
     */
    private function enqueue(string $command, array $parameters): Future
    {
        $deferred = new DeferredFuture();
        $this->queue->push([$deferred, $command, $parameters]);

        $this->connection?->reference();

        try {
            $this->connection?->send($command, ...$parameters);
        } catch (RedisException) {
            $this->connection = null;
        }

        return $deferred->getFuture();
    }

    private function run(): void
    {
        $connector = $this->connector;
        $queue = $this->queue;
        $running = &$this->running;
        $connection = &$this->connection;
        $database = &$this->database;

        EventLoop::queue(static function () use (&$connection, &$running, &$database, $queue, $connector): void {
            try {
                while ($running) {
                    if ($database !== null) {
                        $connection = (new DatabaseSelector($database, $connector))->connect();
                    } else {
                        $connection = $connector->connect();
                    }

                    $connection->unreference();

                    try {
                        foreach ($queue as [$deferred, $command, $parameters]) {
                            $connection->reference();
                            $connection->send($command, ...$parameters);
                        }

                        while ($response = $connection->receive()) {
                            /** @var DeferredFuture $deferred */
                            [$deferred] = $queue->shift();
                            if ($queue->isEmpty()) {
                                $connection->unreference();
                            }

                            $deferred->complete($response);
                        }
                    } catch (RedisException) {
                        // Attempt to reconnect after failure.
                    } finally {
                        $connection = null;
                    }
                }
            } catch (\Throwable $exception) {
                $exception = new RedisConnectionException($exception->getMessage(), 0, $exception);

                while (!$queue->isEmpty()) {
                    /** @var DeferredFuture $deferred */
                    [$deferred] = $queue->shift();
                    $deferred->error($exception);
                }

                $running = false;
            }
        });

        $this->running = true;
    }
}
