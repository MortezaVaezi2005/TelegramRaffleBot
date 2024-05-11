<?php declare(strict_types=1);

namespace Amp\Sql;

use Amp\Closable;

interface SqlTransientResource extends Closable
{
    /**
     * Get the timestamp of the last usage of this resource.
     *
     * @return int Unix timestamp in seconds.
     */
    public function getLastUsedAt(): int;
}
