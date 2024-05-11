<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Sql\SqlConnectionException;
use Revolt\EventLoop;

/** @internal */
final class MysqlConnectionStatement implements MysqlStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly int $totalParamCount;
    private readonly int $positionalParamCount;

    private array $named = [];

    /** @var array<string> */
    private array $prebound = [];

    private ?ConnectionProcessor $processor;

    private int $lastUsedAt;

    private readonly DeferredFuture $onClose;

    public function __construct(
        ConnectionProcessor $processor,
        private readonly string $query,
        private readonly int $statementId,
        private readonly array $byNamed,
        private readonly MysqlResultProxy $result
    ) {
        $this->processor = $processor;
        $this->totalParamCount = $this->result->columnsToFetch;

        $this->onClose = new DeferredFuture();

        $positionalParamCount = $this->totalParamCount;
        foreach ($this->byNamed as $name => $ids) {
            foreach ($ids as $id) {
                $this->named[$id] = $name;
                $positionalParamCount--;
            }
        }

        $this->positionalParamCount = $positionalParamCount;

        $this->lastUsedAt = \time();
    }

    private function getProcessor(): ConnectionProcessor
    {
        if ($this->processor === null) {
            throw new \Error("The statement has been closed");
        }

        if ($this->processor->isClosed()) {
            throw new SqlConnectionException("Connection went away");
        }

        return $this->processor;
    }

    public function isClosed(): bool
    {
        return !$this->processor || $this->processor->isClosed();
    }

    public function close(): void
    {
        if ($this->processor) {
            self::shutdown($this->processor, $this->statementId, $this->onClose);
            $this->processor = null;
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function bind(int|string $paramId, string $data): void
    {
        if (\is_int($paramId)) {
            if ($paramId >= $this->positionalParamCount || $paramId < 0) {
                throw new \Error("Parameter $paramId is not defined for this prepared statement");
            }
            $i = $paramId;
        } else {
            if (!isset($this->byNamed[$paramId])) {
                throw new \Error("Named parameter :$paramId is not defined for this prepared statement");
            }
            $array = $this->byNamed[$paramId];
            $i = \reset($array);
        }

        do {
            $realId = -1;
            while (isset($this->named[++$realId]) || $i-- > 0) {
                if (!\is_numeric($paramId) && isset($this->named[$realId]) && $this->named[$realId] === $paramId) {
                    break;
                }
            }

            $this->getProcessor()->bindParam($this->statementId, $realId, $data);
        } while (isset($array) && $i = \next($array));

        $prior = $this->prebound[$paramId] ?? '';
        $this->prebound[$paramId] = $prior . $data;
    }

    public function execute(array $params = []): MysqlResult
    {
        $this->lastUsedAt = \time();

        $prebound = $args = [];
        for ($unnamed = $i = 0; $i < $this->totalParamCount; $i++) {
            if (isset($this->named[$i])) {
                $name = $this->named[$i];
                if (\array_key_exists($name, $params)) {
                    $args[$i] = $params[$name];
                } elseif (!\array_key_exists($name, $this->prebound)) {
                    throw new \Error("Named parameter '$name' missing for executing prepared statement");
                } else {
                    $prebound[$i] = $this->prebound[$name];
                }
            } elseif (\array_key_exists($unnamed, $params)) {
                $args[$i] = $params[$unnamed];
                $unnamed++;
            } elseif (!\array_key_exists($unnamed, $this->prebound)) {
                throw new \Error("Parameter $unnamed missing for executing prepared statement");
            } else {
                $prebound[$i] = $this->prebound[$unnamed++];
            }
        }

        return $this->getProcessor()
            ->execute($this->statementId, $this->query, $this->result->params, $prebound, $args)
            ->await();
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function reset(): void
    {
        $this->getProcessor()
            ->resetStmt($this->statementId)
            ->await();
    }

    public function getColumnDefinitions(): array
    {
        return $this->result->getColumnDefinitions();
    }

    public function getParameterDefinitions(): array
    {
        return $this->result->getParameterDefinitions();
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function __destruct()
    {
        if ($this->processor) {
            EventLoop::queue(self::shutdown(...), $this->processor, $this->statementId, $this->onClose);
        }
    }

    private static function shutdown(ConnectionProcessor $processor, int $stmtId, DeferredFuture $onClose): void
    {
        try {
            $processor->closeStmt($stmtId);
            $processor->unreference();
        } finally {
            $onClose->complete();
        }
    }
}
