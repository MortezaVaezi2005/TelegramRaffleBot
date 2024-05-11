<?php declare(strict_types=1);
/** @noinspection DuplicatedCode */

namespace Amp\Redis\Command;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Redis\RedisClient;

final class RedisHyperLogLog
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly RedisClient $client,
        private readonly string $key,
    ) {
    }

    /**
     * @link https://redis.io/commands/pfadd
     */
    public function add(string $element, string ...$elements): bool
    {
        return (bool) $this->client->execute(
            'pfadd',
            $this->key,
            $element,
            ...$elements,
        );
    }

    /**
     * @link https://redis.io/commands/pfcount
     */
    public function count(): int
    {
        return $this->client->execute('pfcount', $this->key);
    }

    /**
     * @link https://redis.io/commands/pfmerge
     */
    public function storeUnion(string $sourceKey, string ...$sourceKeys): void
    {
        $this->client->execute(
            'pfmerge',
            $this->key,
            $sourceKey,
            ...$sourceKeys,
        );
    }
}
