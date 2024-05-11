<?php declare(strict_types=1);

namespace Amp\Redis\Connection;

use Amp\Cancellation;
use Amp\Redis\RedisException;

interface RedisConnector
{
    /**
     * @throws RedisException
     */
    public function connect(?Cancellation $cancellation = null): RedisConnection;
}
