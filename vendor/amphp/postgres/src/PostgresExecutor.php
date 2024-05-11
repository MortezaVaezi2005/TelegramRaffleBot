<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlExecutor;

/**
 * @extends SqlExecutor<PostgresResult, PostgresStatement>
 */
interface PostgresExecutor extends SqlExecutor
{
    /**
     * @return PostgresResult Result object specific to this library.
     */
    public function query(string $sql): PostgresResult;

    /**
     * @return PostgresStatement Statement object specific to this library.
     */
    public function prepare(string $sql): PostgresStatement;

    /**
     * @return PostgresResult Result object specific to this library.
     */
    public function execute(string $sql, array $params = []): PostgresResult;

    /**
     * @param non-empty-string $channel Channel name.
     * @param string $payload Notification payload.
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     */
    public function notify(string $channel, string $payload = ""): PostgresResult;

    /**
     * Quotes (escapes) the given string for use as a string literal in a query. This method wraps the
     * string in single quotes, so additional quotes should not be added in the query.
     *
     * @param string $data Unquoted data.
     *
     * @return string Quoted string literal.
     *
     * @throws \Error If the connection to the database has been closed.
     */
    public function quoteLiteral(string $data): string;

    /**
     * Quotes (escapes) the given string for use as an identifier in a query.
     *
     * @param string $name Unquoted identifier.
     *
     * @return string Quoted identifier.
     *
     * @throws \Error If the connection to the database has been closed.
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Escapes a binary string to be used as BYTEA data.
     */
    public function escapeByteA(string $data): string;
}
