<?php

declare(strict_types=1);

namespace Prometheus;

use InvalidArgumentException;
use Prometheus\Storage\Adapter;

class Histogram extends Collector
{
    const TYPE = 'histogram';

    /**
     * @var float[]|null
     */
    private $buckets;

    /**
     * @param Adapter    $adapter
     * @param string     $namespace
     * @param string     $name
     * @param string     $help
     * @param string[]   $labels
     * @param float[]|null $buckets
     */
    public function __construct(
        Adapter $adapter,
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        array $buckets = null
    ) {
        parent::__construct($adapter, $namespace, $name, $help, $labels);

        if (null === $buckets) {
            $buckets = self::getDefaultBuckets();
        }

        if (0 === count($buckets)) {
            throw new InvalidArgumentException("Histogram must have at least one bucket.");
        }

        for ($i = 0; $i < count($buckets) - 1; $i++) {
            if ($buckets[$i] >= $buckets[$i + 1]) {
                throw new InvalidArgumentException(
                    "Histogram buckets must be in increasing order: " .
                    $buckets[$i] . " >= " . $buckets[$i + 1]
                );
            }
        }
        if (in_array('le', $labels, true)) {
            throw new \InvalidArgumentException("Histogram cannot have a label named 'le'.");
        }
        $this->buckets = $buckets;
    }

    /**
     * List of default buckets suitable for typical web application latency metrics
     *
     * @return float[]
     */
    public static function getDefaultBuckets(): array
    {
        return [
            0.005,
            0.01,
            0.025,
            0.05,
            0.075,
            0.1,
            0.25,
            0.5,
            0.75,
            1.0,
            2.5,
            5.0,
            7.5,
            10.0,
        ];
    }

    /**
     * @param float $start
     * @param float $growthFactor
     * @param int   $numberOfBuckets
     *
     * @return float[]
     */
    public static function exponentialBuckets(float $start, float $growthFactor, int $numberOfBuckets): array
    {
        if ($numberOfBuckets < 1) {
            throw new InvalidArgumentException('Number of buckets must be a positive integer');
        }

        if ($start <= 0) {
            throw new InvalidArgumentException('The starting position of a set of buckets must be a positive integer');
        }

        if ($growthFactor <= 1) {
            throw new InvalidArgumentException('The growth factor must greater than 1');
        }

        $buckets = [];

        for ($i = 0; $i < $numberOfBuckets; $i++) {
            $buckets[$i] = $start;
            $start       *= $growthFactor;
        }

        return $buckets;
    }

    /**
     * @param double $value e.g. 123
     * @param string[]  $labels e.g. ['status', 'opcode']
     */
    public function observe(float $value, array $labels = []): void
    {
        $this->assertLabelsAreDefinedCorrectly($labels);

        $this->storageAdapter->updateHistogram(
            [
                'value'       => $value,
                'name'        => $this->getName(),
                'help'        => $this->getHelp(),
                'type'        => $this->getType(),
                'labelNames'  => $this->getLabelNames(),
                'labelValues' => $labels,
                'buckets'     => $this->buckets,
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
