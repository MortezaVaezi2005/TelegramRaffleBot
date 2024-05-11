<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;

/**
 * @template T
 *
 * @internal
 */
final class StatementStorage
{
    use ForbidCloning;
    use ForbidSerialization;

    public int $refCount = 1;

    /**
     * @param Future<T>|Future<void> $future
     */
    public function __construct(
        public readonly string $sql,
        public Future $future,
    ) {
    }
}
