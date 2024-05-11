<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Mysql\MysqlColumnDefinition;
use Amp\Mysql\MysqlResult;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;

/**
 * @internal
 * @psalm-import-type TFieldType from MysqlResult
 */
final class MysqlResultProxy
{
    use ForbidCloning;
    use ForbidSerialization;

    public int $columnsToFetch = 0;

    /** @var list<MysqlColumnDefinition> */
    public array $columns = [];

    /** @var list<MysqlColumnDefinition> */
    public array $params = [];

    /** @var Queue<list<TFieldType>> */
    private readonly Queue $rowQueue;

    /** @var ConcurrentIterator<list<TFieldType>> */
    public readonly ConcurrentIterator $rowIterator;

    private ?DeferredFuture $columnDeferred = null;

    private MysqlResultProxyState $state = MysqlResultProxyState::Initial;

    /** @var DeferredFuture<MysqlResult|null>|null */
    public ?DeferredFuture $next = null;

    public function __construct(
        public readonly int $columnCount = 0,
        ?int $columnsToFetch = null,
        public readonly ?int $affectedRows = null,
        public readonly ?int $insertId = null,
    ) {
        $this->rowQueue = new Queue();
        $this->rowIterator = $this->rowQueue->iterate();

        $this->columnsToFetch = $columnsToFetch ?? $this->columnCount;
    }

    /**
     * @return list<MysqlColumnDefinition>
     */
    public function getColumnDefinitions(): array
    {
        if ($this->state === MysqlResultProxyState::Initial) {
            $this->columnDeferred ??= new DeferredFuture();
            $this->columnDeferred->getFuture()->await();
        }

        return $this->columns;
    }

    /**
     * @return list<MysqlColumnDefinition>
     */
    public function getParameterDefinitions(): array
    {
        if ($this->state === MysqlResultProxyState::Initial) {
            $this->columnDeferred ??= new DeferredFuture();
            $this->columnDeferred->getFuture()->await();
        }

        return $this->params;
    }

    public function markDefinitionsFetched(): void
    {
        \assert($this->state === MysqlResultProxyState::Initial, 'Result proxy in invalid state');

        $this->state = MysqlResultProxyState::Fetched;
        $this->columnDeferred?->complete();
        $this->columnDeferred = null;
    }

    /**
     * @param list<TFieldType> $row
     */
    public function pushRow(array $row): void
    {
        \assert($this->state === MysqlResultProxyState::Fetched, 'Result proxy in invalid state');

        $this->rowQueue->push($row);
    }

    public function complete(): void
    {
        \assert($this->state === MysqlResultProxyState::Fetched, 'Result proxy in invalid state');

        $this->state = MysqlResultProxyState::Complete;

        if (!$this->rowQueue->isComplete()) {
            $this->rowQueue->complete();
        }
    }

    public function error(\Throwable $e): void
    {
        if ($this->state === MysqlResultProxyState::Complete) {
            return;
        }

        $this->state = MysqlResultProxyState::Complete;

        if (!$this->rowQueue->isComplete()) {
            $this->rowQueue->error($e);
        }

        $this->columnDeferred?->error($e);
        $this->columnDeferred = null;
    }
}
