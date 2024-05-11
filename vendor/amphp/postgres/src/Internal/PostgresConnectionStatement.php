<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Sql\SqlException;

/** @internal  */
final class PostgresConnectionStatement implements PostgresStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    private int $lastUsedAt;

    private readonly DeferredFuture $onClose;

    /**
     * @param string $name Statement name.
     * @param string $sql Original prepared SQL query.
     * @param list<int|string> $params Parameter indices to parameter names.
     */
    public function __construct(
        private readonly PostgresHandle $handle,
        private readonly string $name,
        private readonly string $sql,
        private readonly array $params,
    ) {
        $this->lastUsedAt = \time();
        $this->onClose = new DeferredFuture();
        $this->onClose(static fn () => $handle->statementDeallocate($name));
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isClosed(): bool
    {
        return $this->onClose->isComplete();
    }

    public function close(): void
    {
        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function getQuery(): string
    {
        return $this->sql;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function execute(array $params = []): PostgresResult
    {
        if ($this->isClosed()) {
            throw new SqlException('The statement has been closed or the connection went away');
        }

        $this->lastUsedAt = \time();
        return $this->handle->statementExecute($this->name, replaceNamedParams($params, $this->params));
    }
}
