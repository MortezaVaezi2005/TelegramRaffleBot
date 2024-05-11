<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TFieldValue
 * @extends \Traversable<int, array<string, TFieldValue>>
 */
interface SqlResult extends \Traversable
{
    /**
     * Returns the next row in the result set or null if no rows remain. This method may be used as an alternative
     * to foreach iteration to obtain single rows from the result.
     *
     * @return array<string, TFieldValue>|null
     */
    public function fetchRow(): ?array;

    /**
     * Resolves with a new instance of Result if another result is available after this result. Resolves with null if
     * no further results are available.
     *
     * @return SqlResult<TFieldValue>|null
     */
    public function getNextResult(): ?self;

    /**
     * Returns the number of rows affected or returned by the query if applicable or null if the number of rows is
     * unknown or not applicable to the query.
     */
    public function getRowCount(): ?int;

    /**
     * Returns the number of columns returned by the query if applicable or null if the number of columns is
     * unknown or not applicable to the query.
     */
    public function getColumnCount(): ?int;
}
