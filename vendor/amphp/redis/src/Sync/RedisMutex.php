<?php declare(strict_types=1);

namespace Amp\Redis\Sync;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisException;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use function Amp\delay;

/**
 * Mutex can be used to create locks for mutual exclusion in distributed clients.
 *
 * @author Niklas Keller <me@kelunik.com>
 */
final class RedisMutex implements KeyedMutex
{
    private const LOCK = <<<LOCK
local lock = KEYS[1]
local queue = KEYS[2]

local token = ARGV[1]
local ttl = ARGV[2]
local queueTtl = ARGV[3]

if redis.call("exists", lock) == 0 then
    if redis.call("llen", queue) == 0 then
        redis.call("set", lock, token, "px", ttl)
        return 1
    else
        local queued_tokens = redis.call("lrange", queue, 0, -1)
        local push = 1
        local position = 0

        for i=1,#queued_tokens do
            if queued_tokens[i] == token then
                push = 0
                position = i
                break
            end
        end

        if push == 1 then
            redis.call("rpush", queue, token)
        end

        local queued = redis.call("lpop", queue)
        redis.call("set", lock, queued, "px", ttl)
        if queued == token then
            return 2
        else
            redis.call("pexpire", queue, queueTtl)

            return -1 - position
        end
    end
else
    if redis.call("get", lock) == token then
        redis.call("set", lock, token, "px", ttl)
        return 1
    end

    local queued_tokens = redis.call("lrange", queue, 0, -1)
    for i=1,#queued_tokens do
        if queued_tokens[i] == token then
            redis.call("pexpire", queue, queueTtl)

            return -1 - i
        end
    end

    redis.call("rpush", queue, token)
    redis.call("pexpire", queue, queueTtl)

    return -1 - redis.call("llen", queue)
end
LOCK;

    private const UNLOCK = <<<UNLOCK
local lock = KEYS[1]
local token = ARGV[1]

if redis.call("get", lock) == token then
    redis.call("del", lock)
    return 1
else
    return 2
end
UNLOCK;

    private const RENEW = <<<RENEW
for i=1,#KEYS do
    if redis.call("get", KEYS[i]) == ARGV[i + 1] then
        redis.call("pexpire", KEYS[i], ARGV[1])
    end
end
RENEW;

    private readonly RedisMutexOptions $options;

    private readonly RedisClient $redis;

    /** @var array<string, array{string, string}> */
    private array $locks = [];

    private ?string $watcher = null;

    private readonly PsrLogger $logger;

    private int $numberOfLocks = 0;

    private int $numberOfAttempts = 0;

    /**
     * Constructs a new Mutex instance. A single instance can be used to create as many locks as you need.
     */
    public function __construct(
        private readonly RedisClient $client,
        ?RedisMutexOptions $options = null,
        ?PsrLogger $logger = null,
    ) {
        $this->options = $options ?? new RedisMutexOptions;
        $this->redis = $client;
        $this->logger = $logger ?? new NullLogger;
    }

    public function __destruct()
    {
        if ($this->watcher !== null) {
            EventLoop::cancel($this->watcher);
        }
    }

    public function getClient(): RedisClient
    {
        return $this->client;
    }

    /**
     * Acquires a lock.
     *
     * If directly acquiring a lock fails, the client is placed in a queue and reattempts to lock the key. If a client
     * crashes or doesn't free the lock while not renewing it, the lock will expire and the next client in the queue
     * will be able to acquire it.
     *
     * @param string $key Lock key.
     */
    public function acquire(string $key): Lock
    {
        $this->numberOfLocks++;

        $token = \base64_encode(\random_bytes(16));
        $prefix = $this->options->getKeyPrefix();
        $timeLimit = \microtime(true) + $this->options->getLockTimeout();
        $attempts = 0;

        do {
            $attempts++;
            $this->numberOfAttempts++;

            $result = $this->redis->eval(
                self::LOCK,
                ["{$prefix}lock:{$key}", "{$prefix}lock-queue:{$key}"],
                [$token, $this->options->getLockExpiration() * 1000, ($this->options->getLockExpiration() + $this->options->getLockTimeout()) * 1000]
            );

            if ($result < 1) {
                if ($attempts > 2 && \microtime(true) > $timeLimit) {
                    // In very rare cases we might not get the lock, but are at the head of the queue and another
                    // client moves us into the lock position. Deleting the token from the queue and afterwards
                    // unlocking solves this. No yield required, because we use the same connection.
                    $this->redis->getList("{$prefix}lock-queue:{$key}")->remove($token);
                    $this->unlock($key, $token);

                    throw new RedisMutexException('Failed to acquire lock for ' . $key . ' within ' . $this->options->getLockTimeout() * 1000 . ' ms');
                }

                // A negative integer as reply means we're still in the queue and indicates the queue position.
                // Making the timing dependent on the queue position greatly reduces CPU usage and locking attempts.
                delay(0.005 + \min((-$result - 1) / 100, 0.3));
            }
        } while ($result < 1);

        if (empty($this->locks)) {
            $this->createRenewWatcher();
        }

        $this->locks[$key . ' @ ' . $token] = [$key, $token];

        return new Lock(fn () => $this->unlock($key, $token));
    }

    public function getNumberOfAttempts(): int
    {
        return $this->numberOfAttempts;
    }

    public function getNumberOfLocks(): int
    {
        return $this->numberOfLocks;
    }

    public function resetStatistics(): void
    {
        $this->numberOfAttempts = 0;
        $this->numberOfLocks = 0;
    }

    /**
     * Unlocks a previously acquired lock.
     *
     * @param string $key Lock key.
     * @param string $token Unique token generated during {@link lock()}.
     */
    private function unlock(string $key, string $token): void
    {
        // Unset before unlocking, as we don't want to renew the lock anymore
        // If something goes wrong, the lock will simply expire
        unset($this->locks[$key . ' @ ' . $token]);

        if (empty($this->locks) && $this->watcher !== null) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }

        $prefix = $this->options->getKeyPrefix();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $result = $this->redis->eval(
                    self::UNLOCK,
                    ["{$prefix}lock:{$key}"],
                    [$token]
                );

                if ($result === 2) {
                    $this->logger->warning('Lock was already expired when unlocked', [
                        'key' => $key,
                    ]);
                }

                break;
            } catch (RedisException $e) {
                $this->logger->error('Unlock operation failed on attempt ' . ($attempt + 1), [
                    'exception' => $e,
                ]);
            }
        }
    }

    private function createRenewWatcher(): void
    {
        $locks = &$this->locks;
        $options = $this->options;
        $redis = $this->redis;
        $logger = $this->logger;

        $this->watcher = EventLoop::repeat(
            $options->getLockRenewInterval(),
            static function () use (&$locks, $options, $redis, $logger): void {
                \assert(!empty($locks));

                $keys = [];
                $arguments = [$options->getLockExpiration() * 1000];

                $prefix = $options->getKeyPrefix();

                foreach ($locks as [$key, $token]) {
                    $keys[] = "{$prefix}lock:{$key}";
                    $arguments[] = $token;
                }

                try {
                    $redis->eval(self::RENEW, $keys, $arguments);
                } catch (RedisException $e) {
                    $logger->error('Renew operation failed, locks might expire', [
                        'exception' => $e,
                    ]);
                }
            }
        );
    }
}
