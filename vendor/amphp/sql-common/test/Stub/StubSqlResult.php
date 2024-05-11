<?php declare(strict_types=1);

namespace Amp\Sql\Common\Test\Stub;

use Amp\Sql\SqlResult;

final class StubSqlResult implements SqlResult, \IteratorAggregate
{
    private readonly array $rows;

    private int $current = 0;

    public function __construct(array $rows, private readonly ?SqlResult $next = null)
    {
        $this->rows = \array_values($rows);
    }

    public function getIterator(): \Iterator
    {
        yield from $this->rows;
    }

    public function fetchRow(): ?array
    {
        return $this->rows[$this->current++] ?? null;
    }

    public function getNextResult(): ?SqlResult
    {
        return $this->next;
    }

    public function getRowCount(): ?int
    {
        return \count($this->rows);
    }

    public function getColumnCount(): ?int
    {
        return null;
    }
}
