<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 *
 * @extends SqlExecutor<TResult, TStatement>
 */
interface SqlLink extends SqlExecutor
{
    /**
     * Starts a transaction, returning an object where all queries are executed on a single connection.
     *
     * @return TTransaction
     */
    public function beginTransaction(): SqlTransaction;
}
