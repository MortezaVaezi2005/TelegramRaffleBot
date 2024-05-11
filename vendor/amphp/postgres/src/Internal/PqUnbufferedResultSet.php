<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Postgres\PostgresResult;
use pq;
use Revolt\EventLoop;

/**
 * @internal
 * @psalm-import-type TRowType from PostgresResult
 * @implements \IteratorAggregate<int, TRowType>
 */
final class PqUnbufferedResultSet implements PostgresResult, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly \Generator $generator;

    private readonly int $columnCount;

    /**
     * @param \Closure():(\pq\Result|null) $fetch Function to fetch next result row.
     * @param \pq\Result $result Initial pq\Result result object.
     * @param Future<PostgresResult|null> $nextResult
     */
    public function __construct(
        \Closure $fetch,
        pq\Result $result,
        private readonly Future $nextResult,
    ) {
        $this->columnCount = $result->numCols;

        $this->generator = self::generate($fetch, $result);
    }

    private static function generate(\Closure $fetch, pq\Result $result): \Generator
    {
        do {
            $result->autoConvert = pq\Result::CONV_SCALAR | pq\Result::CONV_ARRAY | pq\Result::CONV_BYTEA;
            yield $result->fetchRow(pq\Result::FETCH_ASSOC);
            $result = $fetch();
        } while ($result instanceof pq\Result);
    }

    public function __destruct()
    {
        EventLoop::queue(self::dispose(...), $this->generator);
    }

    private static function dispose(\Generator $generator): void
    {
        try {
            // Discard remaining rows in the result set.
            while ($generator->valid()) {
                $generator->next();
            }
        } catch (\Throwable) {
            // Ignore errors while discarding result.
        }
    }

    public function fetchRow(): ?array
    {
        if (!$this->generator->valid()) {
            return null;
        }

        $current = $this->generator->current();
        $this->generator->next();
        return $current;
    }

    public function getIterator(): \Traversable
    {
        // Using a Generator to keep a reference to $this.
        yield from $this->generator;
    }

    public function getNextResult(): ?PostgresResult
    {
        self::dispose($this->generator);

        return $this->nextResult->await();
    }

    public function getRowCount(): ?int
    {
        return null; // Unbuffered result sets do not have a total row count.
    }

    public function getColumnCount(): int
    {
        return $this->columnCount;
    }
}
