<?php

declare(strict_types=1);

namespace Prometheus;

use InvalidArgumentException;
use Prometheus\Storage\Adapter;

class Summary extends Collector
{
    const RESERVED_LABELS = ['quantile'];
    const TYPE = 'summary';

    /**
     * @var float[]|null
     */
    private $quantiles;

    /**
     * @var int
     */
    private $maxAgeSeconds;

    /**
     * @param Adapter    $adapter
     * @param string     $namespace
     * @param string     $name
     * @param string     $help
     * @param string[]   $labels
     * @param int        $maxAgeSeconds
     * @param float[]    $quantiles
     */
    public function __construct(
        Adapter $adapter,
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        int $maxAgeSeconds = 600,
        array $quantiles = null
    ) {
        parent::__construct($adapter, $namespace, $name, $help, $labels);

        if (null === $quantiles) {
            $quantiles = self::getDefaultQuantiles();
        }

        if (0 === count($quantiles)) {
            throw new InvalidArgumentException("Summary must have at least one quantile.");
        }

        for ($i = 0; $i < count($quantiles) - 1; $i++) {
            if ($quantiles[$i] >= $quantiles[$i + 1]) {
                throw new InvalidArgumentException(
                    "Summary quantiles must be in increasing order: " .
                    $quantiles[$i] . " >= " . $quantiles[$i + 1]
                );
            }
        }

        foreach ($quantiles as $quantile) {
            if ($quantile <= 0 || $quantile >= 1) {
                throw new InvalidArgumentException("Quantile $quantile invalid: Expected number between 0 and 1.");
            }
        }

        if ($maxAgeSeconds <= 0) {
            throw new InvalidArgumentException("maxAgeSeconds $maxAgeSeconds invalid: Expected number greater than 0.");
        }

        if (count(array_intersect(self::RESERVED_LABELS, $labels)) > 0) {
            throw new InvalidArgumentException("Summary cannot have a label named " . implode(', ', self::RESERVED_LABELS) . ".");
        }
        $this->quantiles = $quantiles;
        $this->maxAgeSeconds = $maxAgeSeconds;
    }

    /**
     * List of default quantiles suitable for typical web application latency metrics
     *
     * @return float[]
     */
    public static function getDefaultQuantiles(): array
    {
        return [
            0.01,
            0.05,
            0.5,
            0.95,
            0.99,
        ];
    }

    /**
     * @param double $value e.g. 123
     * @param string[]  $labels e.g. ['status', 'opcode']
     */
    public function observe(float $value, array $labels = []): void
    {
        $this->assertLabelsAreDefinedCorrectly($labels);

        $this->storageAdapter->updateSummary(
            [
                'value'         => $value,
                'name'          => $this->getName(),
                'help'          => $this->getHelp(),
                'type'          => $this->getType(),
                'labelNames'    => $this->getLabelNames(),
                'labelValues'   => $labels,
                'maxAgeSeconds' => $this->maxAgeSeconds,
                'quantiles'     => $this->quantiles,
            ]
        );
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE;
    }
}
