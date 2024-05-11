<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TResult of SqlResult
 */
interface SqlStatement extends SqlTransientResource
{
    /**
     * @return TResult
     */
    public function execute(array $params = []): SqlResult;

    /**
     * @return string The SQL string used to prepare the statement.
     */
    public function getQuery(): string;
}
