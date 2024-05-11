<?php declare(strict_types=1);

namespace Amp\Sql;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement
 */
interface SqlExecutor extends SqlTransientResource
{
    /**
     * @param string $sql SQL query to execute.
     *
     * @return TResult
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     * @throws SqlQueryError If the operation fails due to an error in the query (such as a syntax error).
     */
    public function query(string $sql): SqlResult;

    /**
     * @param string $sql SQL query to prepare.
     *
     * @return TStatement
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     * @throws SqlQueryError If the operation fails due to an error in the query (such as a syntax error).
     */
    public function prepare(string $sql): SqlStatement;

    /**
     * @param string $sql SQL query to prepare and execute.
     * @param array<int, mixed>|array<string, mixed> $params Query parameters.
     *
     * @return TResult
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     * @throws SqlQueryError If the operation fails due to an error in the query (such as a syntax error).
     */
    public function execute(string $sql, array $params = []): SqlResult;
}
