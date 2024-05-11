<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresListener;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Sql\Common\SqlNestableTransactionExecutor;

/**
 * @internal
 * @extends SqlNestableTransactionExecutor<PostgresResult, PostgresStatement>
 */
interface PostgresHandle extends PostgresExecutor, SqlNestableTransactionExecutor
{
    public const STATEMENT_NAME_PREFIX = "amp_";

    public function getConfig(): PostgresConfig;

    /**
     * @param non-empty-string $channel
     */
    public function listen(string $channel): PostgresListener;

    /**
     * Execute the statement with the given name and parameters.
     *
     * @param list<mixed> $params List of statement parameters, indexed starting at 0.
     */
    public function statementExecute(string $name, array $params): PostgresResult;

    /**
     * Deallocate the statement with the given name.
     */
    public function statementDeallocate(string $name): void;
}
