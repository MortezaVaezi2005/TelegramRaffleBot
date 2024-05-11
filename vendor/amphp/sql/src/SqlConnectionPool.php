<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TConfig of SqlConfig
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 * @template TTransaction of SqlTransaction
 *
 * @extends SqlConnection<TConfig, TResult, TStatement, TTransaction>
 */
interface SqlConnectionPool extends SqlConnection
{
    /**
     * Gets a single connection from the pool to run a set of queries against a single connection.
     * Generally a transaction should be used instead of this method.
     *
     * @return SqlConnection<TConfig, TResult, TStatement, TTransaction>
     */
    public function extractConnection(): SqlConnection;

    /**
     * @return int Total number of active connections in the pool.
     */
    public function getConnectionCount(): int;

    /**
     * @return int Total number of idle connections in the pool.
     */
    public function getIdleConnectionCount(): int;

    /**
     * @return int Maximum number of connections this pool will create.
     */
    public function getConnectionLimit(): int;

    /**
     * @return int Number of seconds a connection may remain idle before it is automatically closed.
     */
    public function getIdleTimeout(): int;
}
