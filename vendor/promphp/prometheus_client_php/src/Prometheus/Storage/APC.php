<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use APCuIterator;
use Prometheus\Exception\StorageException;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use RuntimeException;

class APC implements Adapter
{
    /** @var string Default prefix to use for APC keys. */
    const PROMETHEUS_PREFIX = 'prom';

    /** @var string Prefix to use for APC keys. */
    private $prometheusPrefix;

    /**
     * APC constructor.
     *
     * @param string $prometheusPrefix Prefix for APCu keys (defaults to {@see PROMETHEUS_PREFIX}).
     *
     * @throws StorageException
     */
    public function __construct(string $prometheusPrefix = self::PROMETHEUS_PREFIX)
    {
        if (!extension_loaded('apcu')) {
            throw new StorageException('APCu extension is not loaded');
        }
        if (!apcu_enabled()) {
            throw new StorageException('APCu is not enabled');
        }

        $this->prometheusPrefix = $prometheusPrefix;
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function collect(bool $sortMetrics = true): array
    {
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges($sortMetrics));
        $metrics = array_merge($metrics, $this->collectCounters($sortMetrics));
        $metrics = array_merge($metrics, $this->collectSummaries());
        return $metrics;
    }

    /**
     * @param mixed[] $data
     */
    public function updateHistogram(array $data): void
    {
        // Initialize the sum
        $sumKey = $this->histogramBucketValueKey($data, 'sum');
        if (!apcu_exists($sumKey)) {
            $new = apcu_add($sumKey, $this->toBinaryRepresentationAsInteger(0));

            // If sum does not exist, assume a new histogram and store the metadata
            if ($new) {
                apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            }
        }

        // Atomically increment the sum
        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $done = false;
        while (!$done) {
            $old = apcu_fetch($sumKey);
            if ($old !== false) {
                $done = apcu_cas($sumKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
            } else {
                $new = apcu_add($sumKey, $this->toBinaryRepresentationAsInteger(0));

                // If sum does not exist, assume a new histogram and store the metadata
                if ($new) {
                    apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                }
            }
        }

        // Figure out in which bucket the observation belongs
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        // Initialize and increment the bucket
        $bucketKey = $this->histogramBucketValueKey($data, $bucketToIncrease);
        if (!apcu_exists($bucketKey)) {
            apcu_add($bucketKey, 0);
        }
        apcu_inc($bucketKey);
    }

    /**
     * @param mixed[] $data
     */
    public function updateSummary(array $data): void
    {
        // store meta
        $metaKey = $this->metaKey($data);
        if (!apcu_exists($metaKey)) {
            apcu_add($metaKey, $this->metaData($data));
        }

        // store value key
        $valueKey = $this->valueKey($data);
        if (!apcu_exists($valueKey)) {
            apcu_add($valueKey, $this->encodeLabelValues($data['labelValues']));
        }

        // trick to handle uniqid collision
        $done = false;
        while (!$done) {
            $sampleKey = $valueKey . ':' . uniqid('', true);
            $done = apcu_add($sampleKey, $data['value'], $data['maxAgeSeconds']);
        }
    }

    /**
     * @param mixed[] $data
     */
    public function updateGauge(array $data): void
    {
        $valueKey = $this->valueKey($data);
        $old = apcu_fetch($valueKey);
        if ($data['command'] === Adapter::COMMAND_SET) {
            $new = $this->toBinaryRepresentationAsInteger($data['value']);
            if ($old === false) {
                apcu_store($valueKey, $new);
                apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                return;
            } else {
                // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
                while (true) {
                    if ($old !== false) {
                        if (apcu_cas($valueKey, $old, $new)) {
                            return;
                        } else {
                            $old = apcu_fetch($valueKey);
                        }
                    } else {
                        // Cache got evicted under our feet? Just consider it a fresh/new insert and move on.
                        apcu_store($valueKey, $new);
                        apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                        return;
                    }
                }
            }
        } else {
            if ($old === false) {
                $new = apcu_add($valueKey, $this->toBinaryRepresentationAsInteger(0));
                if ($new) {
                    apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                }
            }
            // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
            $done = false;
            while (!$done) {
                $old = apcu_fetch($valueKey);
                if ($old !== false) {
                    $done = apcu_cas($valueKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
                } else {
                    $new = apcu_add($valueKey, $this->toBinaryRepresentationAsInteger(0));
                    if ($new) {
                        apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                    }
                }
            }
        }
    }

    /**
     * @param mixed[] $data
     */
    public function updateCounter(array $data): void
    {
        $valueKey = $this->valueKey($data);
        // Check if value key already exists
        if (apcu_exists($this->valueKey($data)) === false) {
            apcu_add($this->valueKey($data), 0);
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
        }

        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $done = false;
        while (!$done) {
            $old = apcu_fetch($valueKey);
            if ($old !== false) {
                $done = apcu_cas($valueKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
            } else {
                apcu_add($this->valueKey($data), 0);
                apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            }
        }
    }

    /**
     * @deprecated use replacement method wipeStorage from Adapter interface
     *
     * @return void
     */
    public function flushAPC(): void
    {
        $this->wipeStorage();
    }

    /**
     * Removes all previously stored data from apcu
     *
     * @return void
     */
    public function wipeStorage(): void
    {
        //                   /      / | PCRE expresion boundary
        //                    ^       | match from first character only
        //                     %s:    | common prefix substitute with colon suffix
        //                        .+  | at least one additional character
        $matchAll = sprintf('/^%s:.+/', $this->prometheusPrefix);

        foreach (new APCuIterator($matchAll) as $key => $value) {
            apcu_delete($key);
        }
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function metaKey(array $data): string
    {
        return implode(':', [$this->prometheusPrefix, $data['type'], $data['name'], 'meta']);
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function valueKey(array $data): string
    {
        return implode(':', [
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value',
        ]);
    }

    /**
     * @param mixed[] $data
     * @param string|int $bucket
     * @return string
     */
    private function histogramBucketValueKey(array $data, $bucket): string
    {
        return implode(':', [
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            $bucket,
            'value',
        ]);
    }

    /**
     * @param mixed[] $data
     * @return mixed[]
     */
    private function metaData(array $data): array
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectCounters(bool $sortMetrics = true): array
    {
        $counters = [];
        foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':counter:.*:meta/') as $counter) {
            $metaData = json_decode($counter['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':counter:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->fromBinaryRepresentationAsInteger($value['value']),
                ];
            }

            if ($sortMetrics) {
                $this->sortSamples($data['samples']);
            }

            $counters[] = new MetricFamilySamples($data);
        }
        return $counters;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectGauges(bool $sortMetrics = true): array
    {
        $gauges = [];
        foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':gauge:.*:meta/') as $gauge) {
            $metaData = json_decode($gauge['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':gauge:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->fromBinaryRepresentationAsInteger($value['value']),
                ];
            }

            if ($sortMetrics) {
                $this->sortSamples($data['samples']);
            }

            $gauges[] = new MetricFamilySamples($data);
        }
        return $gauges;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectHistograms(): array
    {
        $histograms = [];
        foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':histogram:.*:meta/') as $histogram) {
            $metaData = json_decode($histogram['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'buckets' => $metaData['buckets'],
            ];

            // Add the Inf bucket so we can compute it later on
            $data['buckets'][] = '+Inf';

            $histogramBuckets = [];
            foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':histogram:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $bucket = $parts[4];
                // Key by labelValues
                $histogramBuckets[$labelValues][$bucket] = $value['value'];
            }

            // Compute all buckets
            $labels = array_keys($histogramBuckets);
            sort($labels);
            foreach ($labels as $labelValues) {
                $acc = 0;
                $decodedLabelValues = $this->decodeLabelValues($labelValues);
                foreach ($data['buckets'] as $bucket) {
                    $bucket = (string)$bucket;
                    if (!isset($histogramBuckets[$labelValues][$bucket])) {
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $histogramBuckets[$labelValues][$bucket];
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_' . 'bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $this->fromBinaryRepresentationAsInteger($histogramBuckets[$labelValues]['sum'] ?? 0),
                ];
            }
            $histograms[] = new MetricFamilySamples($data);
        }
        return $histograms;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectSummaries(): array
    {
        $math = new Math();
        $summaries = [];
        foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':summary:.*:meta/') as $summary) {
            $metaData = $summary['value'];
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'maxAgeSeconds' => $metaData['maxAgeSeconds'],
                'quantiles' => $metaData['quantiles'],
                'samples' => [],
            ];

            foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':summary:' . $metaData['name'] . ':.*:value$/') as $value) {
                $encodedLabelValues = $value['value'];
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);
                $samples = [];
                foreach (new APCuIterator('/^' . $this->prometheusPrefix . ':summary:' . $metaData['name'] . ':' . str_replace('/', '\\/', preg_quote($encodedLabelValues)) . ':value:.*/') as $sample) {
                    $samples[] = $sample['value'];
                }

                if (count($samples) === 0) {
                    apcu_delete($value['key']);
                    continue;
                }

                // Compute quantiles
                sort($samples);
                foreach ($data['quantiles'] as $quantile) {
                    $data['samples'][] = [
                        'name' => $metaData['name'],
                        'labelNames' => ['quantile'],
                        'labelValues' => array_merge($decodedLabelValues, [$quantile]),
                        'value' => $math->quantile($samples, $quantile),
                    ];
                }

                // Add the count
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => count($samples),
                ];

                // Add the sum
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => array_sum($samples),
                ];
            }

            if (count($data['samples']) > 0) {
                $summaries[] = new MetricFamilySamples($data);
            } else {
                apcu_delete($summary['key']);
            }
        }
        return $summaries;
    }

    /**
     * @param mixed $val
     * @return int
     * @throws RuntimeException
     */
    private function toBinaryRepresentationAsInteger($val): int
    {
        $packedDouble = pack('d', $val);
        if ((bool)$packedDouble !== false) {
            $unpackedData = unpack("Q", $packedDouble);
            if (is_array($unpackedData)) {
                return $unpackedData[1];
            }
        }
        throw new RuntimeException("Formatting from binary representation to integer did not work");
    }

    /**
     * @param mixed $val
     * @return float
     * @throws RuntimeException
     */
    private function fromBinaryRepresentationAsInteger($val): float
    {
        $packedBinary = pack('Q', $val);
        if ((bool)$packedBinary !== false) {
            $unpackedData = unpack("d", $packedBinary);
            if (is_array($unpackedData)) {
                return $unpackedData[1];
            }
        }
        throw new RuntimeException("Formatting from integer to binary representation did not work");
    }

    /**
     * @param mixed[] $samples
     */
    private function sortSamples(array &$samples): void
    {
        usort($samples, function ($a, $b): int {
            return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
        });
    }

    /**
     * @param mixed[] $values
     * @return string
     * @throws RuntimeException
     */
    private function encodeLabelValues(array $values): string
    {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    /**
     * @param string $values
     * @return mixed[]
     * @throws RuntimeException
     */
    private function decodeLabelValues(string $values): array
    {
        $json = base64_decode($values, true);
        if (false === $json) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if (false === $decodedValues) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $decodedValues;
    }
}
