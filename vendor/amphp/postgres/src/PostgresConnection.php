<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlConnection;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;

/**
 * @extends SqlConnection<PostgresConfig, PostgresResult, PostgresStatement, PostgresTransaction>
 */
interface PostgresConnection extends PostgresLink, SqlConnection
{
    /**
     * @return PostgresConfig Config object specific to this library.
     */
    public function getConfig(): PostgresConfig;

    /**
     * @param non-empty-string $channel Channel name.
     *
     * @throws SqlException If the operation fails due to unexpected condition.
     * @throws SqlConnectionException If the connection to the database is lost.
     * @throws SqlQueryError If the operation fails due to an error in the query (such as a syntax error).
     */
    public function listen(string $channel): PostgresListener;
}
