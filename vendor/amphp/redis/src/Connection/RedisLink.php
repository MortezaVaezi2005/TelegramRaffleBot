<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\Redis\Protocol\RedisResponse;

interface RedisLink
{
    /**
     * @param array<int|float|string> $parameters
     */
    public function execute(string $command, array $parameters): RedisResponse;
}
