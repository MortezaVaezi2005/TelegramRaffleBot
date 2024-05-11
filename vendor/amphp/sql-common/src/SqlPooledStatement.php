<?php declare(strict_types=1);

namespace Amp\Sql\Common;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sql\SqlException;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Revolt\EventLoop;

/**
 * @template TResult of SqlResult
 * @template TStatement of SqlStatement<TResult>
 *
 * @implements SqlStatement<TResult>
 */
abstract class SqlPooledStatement implements SqlStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure():void */
    private ?\Closure $release;

    private int $refCount = 1;

    /**
     * Creates a Result of the appropriate type using the Result object returned by the Statement object and the
     * given release callable.
     *
     * @param TResult $result
     * @param \Closure():void $release
     *
     * @return TResult
     */
    abstract protected function createResult(SqlResult $result, \Closure $release): SqlResult;

    /**
     * @param TStatement $statement Statement object created by pooled connection.
     * @param \Closure():void $release Callable to be invoked when the statement and any associated results are
     *     destroyed.
     * @param (\Closure():void)|null $awaitBusyResource Callable invoked before executing the statement, which should
     *     wait if the parent resource is busy with another action (e.g., a nested transaction).
     */
    public function __construct(
        private readonly SqlStatement $statement,
        \Closure $release,
        private readonly ?\Closure $awaitBusyResource = null,
    ) {
        $refCount = &$this->refCount;
        $this->release = static function () use (&$refCount, $release): void {
            if (--$refCount === 0) {
                $release();
            }
        };
    }

    public function __destruct()
    {
        $this->dispose();
    }

    /**
     * @return TResult
     */
    public function execute(array $params = []): SqlResult
    {
        if (!$this->release) {
            throw new SqlException('The statement has been closed');
        }

        $this->awaitBusyResource && ($this->awaitBusyResource)();

        $result = $this->statement->execute($params);

        ++$this->refCount;
        return $this->createResult($result, $this->release);
    }

    private function dispose(): void
    {
        if ($this->release) {
            EventLoop::queue($this->release);
            $this->release = null;
        }
    }

    public function isClosed(): bool
    {
        return $this->statement->isClosed();
    }

    public function close(): void
    {
        $this->dispose();
        $this->statement->close();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->statement->onClose($onClose);
    }

    public function getQuery(): string
    {
        return $this->statement->getQuery();
    }

    public function getLastUsedAt(): int
    {
        return $this->statement->getLastUsedAt();
    }
}
