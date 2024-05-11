<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Mysql\MysqlExecutor;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Sql\Common\SqlNestableTransactionExecutor;

/**
 * @internal
 * @implements SqlNestableTransactionExecutor<MysqlResult, MysqlStatement>
 */
final class MysqlNestableExecutor implements MysqlExecutor, SqlNestableTransactionExecutor
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly ConnectionProcessor $processor,
    ) {
    }

    public function isClosed(): bool
    {
        return $this->processor->isClosed();
    }

    /**
     * @return int Timestamp of the last time this connection was used.
     */
    public function getLastUsedAt(): int
    {
        return $this->processor->getLastUsedAt();
    }

    public function close(): void
    {
        // Send close command if connection is not already in a closed or closing state
        if (!$this->processor->isClosed()) {
            $this->processor->sendClose()->await();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->processor->onClose($onClose);
    }

    public function query(string $sql): MysqlResult
    {
        return $this->processor->query($sql)->await();
    }

    public function prepare(string $sql): MysqlStatement
    {
        return $this->processor->prepare($sql)->await();
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        $statement = $this->prepare($sql);
        return $statement->execute($params);
    }

    public function commit(): void
    {
        $this->query("COMMIT");
    }

    public function rollback(): void
    {
        $this->query("ROLLBACK");
    }

    public function createSavepoint(string $identifier): void
    {
        $this->query(\sprintf("SAVEPOINT `%s`", $identifier));
    }

    public function rollbackTo(string $identifier): void
    {
        $this->query(\sprintf("ROLLBACK TO `%s`", $identifier));
    }

    public function releaseSavepoint(string $identifier): void
    {
        $this->query(\sprintf("RELEASE SAVEPOINT `%s`", $identifier));
    }
}
