<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TConfig of SqlConfig
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 *
 * @extends SqlLink<TResult, TStatement, TTransaction>
 */
interface SqlConnection extends SqlLink
{
    /**
     * @return TConfig The configuration used to create this connection.
     */
    public function getConfig(): SqlConfig;

    /**
     * @return SqlTransactionIsolation Current transaction isolation used when beginning transactions on this connection.
     */
    public function getTransactionIsolation(): SqlTransactionIsolation;

    /**
     * Sets the transaction isolation level for transactions began on this link.
     *
     * @see SqlLink::beginTransaction()
     */
    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void;
}
