<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlLink;

/**
 * @extends SqlLink<PostgresResult, PostgresStatement, PostgresTransaction>
 */
interface PostgresLink extends PostgresExecutor, SqlLink
{
    /**
     * @return PostgresTransaction Transaction object specific to this library.
     */
    public function beginTransaction(): PostgresTransaction;
}
