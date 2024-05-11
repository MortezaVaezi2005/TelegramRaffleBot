<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use APCuIterator;
use Prometheus\Exception\StorageException;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use RuntimeException;
use UnexpectedValueException;

class APCng implements Adapter
{
    /** @var string Default prefix to use for APCu keys. */
    const PROMETHEUS_PREFIX = 'prom';

    private const MAX_LOOPS = 10;

    /**
     * @var int
     */
    private $precisionMultiplier;

    /** @var string APCu key where array of all discovered+created metainfo keys is stored */
    private $metainfoCacheKey;

    /** @var string APCu key where count of all added metainfo keys is stored */
    private $metaInfoCounterKey;

    /** @var string APCu key pattern where array of all added metainfo keys is stored */
    private $metaInfoCountedMetricKeyPattern;

    /** @var string Prefix to use for APCu keys. */
    private $prometheusPrefix;

    /**
     * @var array<string, array<mixed>>
     */
    private $metaCache = [];

    /**
     * APCng constructor.
     *
     * @param string $prometheusPrefix Prefix for APCu keys (defaults to {@see PROMETHEUS_PREFIX}).
     *
     * @throws StorageException
     */
    public function __construct(string $prometheusPrefix = self::PROMETHEUS_PREFIX, int $decimalPrecision = 3)
    {
        if (!extension_loaded('apcu')) {
            throw new StorageException('APCu extension is not loaded');
        }
        if (!apcu_enabled()) {
            throw new StorageException('APCu is not enabled');
        }

        $this->prometheusPrefix = $prometheusPrefix;
        $this->metainfoCacheKey = implode(':', [ $this->prometheusPrefix, 'metainfocache' ]);
        $this->metaInfoCounterKey = implode(':', [ $this->prometheusPrefix, 'metainfocounter' ]);
        $this->metaInfoCountedMetricKeyPattern = implode(':', [ $this->prometheusPrefix, 'metainfocountedmetric_#COUNTER#' ]);

        if ($decimalPrecision < 0 || $decimalPrecision > 6) {
            throw new UnexpectedValueException(
                sprintf('Decimal precision %d is not from interval <0;6>.', $decimalPrecision)
            );
        }

        $this->precisionMultiplier = 10 ** $decimalPrecision;
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
     * @throws RuntimeException
     */
    public function updateHistogram(array $data): void
    {
        // Initialize or atomically increment the sum
        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $sumKey = $this->histogramBucketValueKey($data, 'sum');

        $old = apcu_fetch($sumKey);

        if ($old === false) {
            // If sum does not exist, initialize it, store the metadata for the new histogram
            apcu_add($sumKey, 0, 0);
            $this->storeMetadata($data);
            $this->storeLabelKeys($data);
        }

        $this->incrementKeyWithValue($sumKey, $data['value']);

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
     * For each second, store an incrementing counter which points to each individual observation, like this:
     *     prom:bla..blabla:value:16781560:observations = 199
     * Then we know that for the 1-second period at unix timestamp 16781560, 199 observations are stored, and they can
     * be retrieved using APC keynames "prom:...:16781560.0" thorough "prom:...:16781560.198"
     * We can deterministically calculate the intervening timestamps by subtracting maxAge, to get a range of seconds
     * when generating a summary, e.g. 16781560 back to 16780960 for a 600sec maxAge. Then collect observation counts
     * for each second, programmatically generate the APC keys for each individual observation, and we're able to avoid
     * performing a full APC key scan, which can block for several seconds if APCu contains a few million keys.
     *
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateSummary(array $data): void
    {
        // store value key; store metadata & labels if new
        $valueKey = $this->valueKey($data);
        $new = apcu_add($valueKey, $this->encodeLabelValues($data['labelValues']), 0);
        if ($new) {
            $this->storeMetadata($data, false);
            $this->storeLabelKeys($data);
        }
        $sampleKeyPrefix = $valueKey . ':' . time();
        $sampleCountKey = $sampleKeyPrefix . ':observations';

        // Check if sample counter for this timestamp already exists, so we can deterministically
        // store observations+counts, one key per second
        // Atomic increment of the observation counter, or initialize if new
        $sampleCount = apcu_fetch($sampleCountKey);

        if ($sampleCount === false) {
            $sampleCount = 0;
            apcu_add($sampleCountKey, $sampleCount, $data['maxAgeSeconds']);
        }

        $this->doIncrementKeyWithValue($sampleCountKey, 1);

        // We now have a deterministic keyname for this observation; let's save the observed value
        $sampleKey = $sampleKeyPrefix . '.' . $sampleCount;
        apcu_add($sampleKey, $data['value'], $data['maxAgeSeconds']);
    }

    /**
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateGauge(array $data): void
    {
        $valueKey = $this->valueKey($data);
        $old = apcu_fetch($valueKey);
        if ($data['command'] === Adapter::COMMAND_SET) {
            $new = $this->convertToIncrementalInteger($data['value']);
            if ($old === false) {
                apcu_store($valueKey, $new, 0);
                $this->storeMetadata($data);
                $this->storeLabelKeys($data);

                return;
            }

            for ($loops = 0; $loops < self::MAX_LOOPS; $loops++) {
                if (apcu_cas($valueKey, $old, $new)) {
                    break;
                }
                $old = apcu_fetch($valueKey);
                if ($old === false) {
                    apcu_store($valueKey, $new, 0);
                    $this->storeMetadata($data);
                    $this->storeLabelKeys($data);

                    return;
                }
            }

            return;
        }

        if ($old === false) {
            apcu_add($valueKey, 0, 0);
            $this->storeMetadata($data);
            $this->storeLabelKeys($data);
        }

        if ($data['value'] > 0) {
            $this->incrementKeyWithValue($valueKey, $data['value']);
        } elseif ($data['value'] < 0) {
            $this->decrementKeyWithValue($valueKey, -$data['value']);
        }
    }

    /**
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateCounter(array $data): void
    {
        $valueKey = $this->valueKey($data);
        $old = apcu_fetch($valueKey);

        if ($old === false) {
            apcu_add($valueKey, 0, 0);
            $this->storeMetadata($data);
            $this->storeLabelKeys($data);
        }

        $this->incrementKeyWithValue($valueKey, $data['value']);
    }

    /**
     * @param array<string> $metaData
     * @param string $labels
     * @return string
     */
    private function assembleLabelKey(array $metaData, string $labels): string
    {
        return implode(':', [ $this->prometheusPrefix, $metaData['type'], $metaData['name'], $labels, 'label' ]);
    }

    /**
     * Store ':label' keys for each metric's labelName in APCu.
     *
     * @param array<mixed> $data
     * @return void
     */
    private function storeLabelKeys(array $data): void
    {
        // Store labelValues in each labelName key
        foreach ($data['labelNames'] as $seq => $label) {
            $this->addItemToKey(implode(':', [
                $this->prometheusPrefix,
                $data['type'],
                $data['name'],
                $label,
                'label'
            ]), isset($data['labelValues']) ? $data['labelValues'][$seq] : ''); // may not need the isset check
        }
    }

    /**
     * Ensures an array serialized into APCu contains exactly one copy of a given string
     *
     * @return void
     * @throws RuntimeException
     */
    private function addItemToKey(string $key, string $item): void
    {
        // Modify serialized array stored in $key
        $arr = apcu_fetch($key);
        if (false === $arr) {
            $arr = [];
        }
        $_item = $this->encodeLabelKey($item);
        if (!array_key_exists($_item, $arr)) {
            $arr[$_item] = 1;
            apcu_store($key, $arr, 0);
        }
    }

    /**
     * Removes all previously stored data from apcu
     *
     * NOTE: This is non-atomic: while it's iterating APCu, another thread could write a new Prometheus key that doesn't get erased.
     * In case this happens, getMetas() calls scanAndBuildMetainfoCache before reading metainfo back: this will ensure "orphaned"
     * metainfo gets enumerated.
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

        foreach (new APCuIterator($matchAll, APC_ITER_KEY) as $key) {
            apcu_delete($key);
        }

        apcu_delete($this->metaInfoCounterKey);
        apcu_delete($this->metainfoCacheKey);
    }

    /**
     * Scans the APCu keyspace for all metainfo keys. A new metainfo cache array is built,
     * which references all metadata keys in APCu at that moment. This prevents a corner-case
     * where an orphaned key, while remaining writable, is rendered permanently invisible when reading
     * or enumerating metrics.
     *
     * Writing the cache to APCu allows it to be shared by other threads and by subsequent calls to getMetas(). This
     * reduces contention on APCu from repeated scans, and provides about a 2.5x speed-up when calling $this->collect().
     * The cache TTL is very short (default: 1sec), so if new metrics are tracked after the cache is built, they will
     * be readable at most 1 second after being written.
     *
     * @return array<string, array<array{key: string, value: array<mixed>}>>
     */
    private function scanAndBuildMetainfoCache(): array
    {
        $arr = [];

        $counter = (int) apcu_fetch($this->metaInfoCounterKey);

        for ($i = 1; $i <= $counter; $i++) {
            $metaCounterKey = $this->metaCounterKey($i);
            $metaKey = apcu_fetch($metaCounterKey);

            if (!is_string($metaKey)) {
                throw new UnexpectedValueException(
                    sprintf('Invalid meta counter key: %s', $metaCounterKey)
                );
            }

            if (preg_match('/' . $this->prometheusPrefix . ':([^:]+):.*:meta/', $metaKey, $matches) !== 1) {
                throw new UnexpectedValueException(
                    sprintf('Invalid meta key: %s', $metaKey)
                );
            }

            $type = $matches[1];

            if (!isset($arr[$type])) {
                $arr[$type] = [];
            }

            /** @var array<mixed>|false $metaInfo */
            $metaInfo = apcu_fetch($metaKey);

            if ($metaInfo === false) {
                throw new UnexpectedValueException(
                    sprintf('Meta info missing for meta key: %s', $metaKey)
                );
            }

            $arr[$type][] = ['key' => $metaKey, 'value' => $metaInfo];
        }

        apcu_store($this->metainfoCacheKey, $arr, 0);

        return $arr;
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
     * When given a ragged 2D array $labelValues of arbitrary size, and a 1D array $labelNames containing one
     * string labeling each row of $labelValues, return an array-of-arrays containing all possible permutations
     * of labelValues, with the sub-array elements in order of labelName.
     *
     * Example input:
     *  $labelNames:  ['endpoint', 'method', 'result']
     *  $labelValues: [0] => ['/', '/private', '/metrics'], // "endpoint"
     *                [1] => ['put', 'get', 'post'],        // "method"
     *                [2] => ['success', 'fail']            // "result"
     * Returned array:
     *  [0] => ['/', 'put', 'success'],          [1] => ['/', 'put', 'fail'],             [2] => ['/', 'get', 'success'],
     *  [3] => ['/', 'get', 'fail'],             [4] => ['/', 'post', 'success'],         [5] => ['/', 'post', 'fail'],
     *  [6] => ['/private', 'put', 'success'],   [7] => ['/private', 'put', 'fail'],      [8] => ['/private', 'get', 'success'],
     *  [9] => ['/private', 'get', 'fail'],     [10] => ['/private', 'post', 'success'], [11] => ['/private', 'post', 'fail'],
     *  [12] => ['/metrics', 'put', 'success'], [13] => ['/metrics', 'put', 'fail'],     [14] => ['/metrics', 'get', 'success'],
     *  [15] => ['/metrics', 'get', 'fail'],    [16] => ['/metrics', 'post', 'success'], [17] => ['/metrics', 'post', 'fail']
     * @param array<string> $labelNames
     * @param array<array> $labelValues
     * @return array<array>
     */
    private function buildPermutationTree(array $labelNames, array $labelValues): array /** @phpstan-ignore-line */
    {
        $treeRowCount = count(array_keys($labelNames));
        $numElements = 1;
        $treeInfo = [];
        for ($i = $treeRowCount - 1; $i >= 0; $i--) {
            $treeInfo[$i]['numInRow'] = count($labelValues[$i]);
            $numElements *= $treeInfo[$i]['numInRow'];
            $treeInfo[$i]['numInTree'] = $numElements;
        }

        $map = array_fill(0, $numElements, []);
        for ($row = 0; $row < $treeRowCount; $row++) {
            $col = $i = 0;
            while ($i < $numElements) {
                $val = $labelValues[$row][$col];
                $map[$i] = array_merge($map[$i], array($val));
                if (++$i % ($treeInfo[$row]['numInTree'] / $treeInfo[$row]['numInRow']) == 0) {
                    $col = ++$col % $treeInfo[$row]['numInRow'];
                }
            }
        }
        return $map;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectCounters(bool $sortMetrics = true): array
    {
        $counters = [];
        foreach ($this->getMetas('counter') as $counter) {
            $metaData = json_decode($counter['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach ($this->getValues('counter', $metaData) as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->convertIncrementalIntegerToFloat($value['value']),
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
     * When given a type ('histogram', 'gauge', or 'counter'), return an iterable array of matching records retrieved from APCu
     *
     * @param string $type
     * @return array<array>
     */
    private function getMetas(string $type): array /** @phpstan-ignore-line */
    {
        $arr = [];
        $counterModified = 0;
        $counterModifiedInfo = apcu_key_info($this->metaInfoCounterKey);

        if ($counterModifiedInfo !== null) {
            $counterModified = (int) $counterModifiedInfo['mtime'];
        }

        $cacheModified = 0;
        $cacheModifiedInfo = apcu_key_info($this->metainfoCacheKey);

        if ($cacheModifiedInfo !== null) {
            $cacheModified = (int) $cacheModifiedInfo['mtime'];
        }

        $cacheNeedsRebuild = $counterModified >= $cacheModified || $cacheModified === 0;
        $metaCache = null;

        if (isset($this->metaCache[$type]) && !$cacheNeedsRebuild) {
            return $this->metaCache[$type];
        }

        if ($cacheNeedsRebuild) {
            $metaCache = $this->scanAndBuildMetainfoCache();
        }

        if ($metaCache === null) {
            $metaCache = apcu_fetch($this->metainfoCacheKey);
        }

        $this->metaCache = $metaCache;

        return $this->metaCache[$type] ?? [];
    }

    /**
     * When given a type ('histogram', 'gauge', or 'counter') and metaData array, return an iterable array of matching records retrieved from APCu
     *
     * @param string $type
     * @param array<mixed> $metaData
     * @return array<array>
     */
    private function getValues(string $type, array $metaData): array /** @phpstan-ignore-line */
    {
        $labels = $arr = [];
        foreach (array_values($metaData['labelNames']) as $label) {
            $labelKey = $this->assembleLabelKey($metaData, $label);
            if (is_array($tmp = apcu_fetch($labelKey))) {
                $labels[] = array_map([$this, 'decodeLabelKey'], array_keys($tmp));
            }
        }
        // Append the histogram bucket-list and the histogram-specific label 'sum' to labels[] then generate the permutations
        if (isset($metaData['buckets'])) {
            $metaData['buckets'][] = 'sum';
            $labels[] = $metaData['buckets'];
            $metaData['labelNames'][] = '__histogram_buckets';
        }

        $labelValuesList = $this->buildPermutationTree($metaData['labelNames'], $labels);
        unset($labels);
        $histogramBucket = '';
        foreach ($labelValuesList as $labelValues) {
            // Extract bucket value from permuted element, if present, then construct the key and retrieve
            if (isset($metaData['buckets'])) {
                $histogramBucket = ':' . array_pop($labelValues);
            }
            $key = $this->prometheusPrefix . ":{$type}:{$metaData['name']}:" . $this->encodeLabelValues($labelValues) . $histogramBucket . ':value';
            if (false !== ($value = apcu_fetch($key))) {
                $arr[] = [ 'key' => $key, 'value' => $value ];
            }
        }
        return $arr;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectGauges(bool $sortMetrics = true): array
    {
        $gauges = [];
        foreach ($this->getMetas('gauge') as $gauge) {
            $metaData = json_decode($gauge['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach ($this->getValues('gauge', $metaData) as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->convertIncrementalIntegerToFloat($value['value']),
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
        foreach ($this->getMetas('histogram') as $histogram) {
            $metaData = json_decode($histogram['value'], true);

            // Add the Inf bucket so we can compute it later on
            $metaData['buckets'][] = '+Inf';

            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'buckets' => $metaData['buckets'],
            ];

            $histogramBuckets = [];
            foreach ($this->getValues('histogram', $metaData) as $value) {
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
                    'value' => $this->convertIncrementalIntegerToFloat($histogramBuckets[$labelValues]['sum'] ?? 0),
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
        foreach ($this->getMetas('summary') as $summary) {
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

            foreach ($this->getValues('summary', $metaData) as $value) {
                $encodedLabelValues = (string) $value['value'];
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);
                $samples = [];

                // Deterministically generate keys for all the sample observations, and retrieve them. Pass arrays to apcu_fetch to reduce calls to APCu.
                $end = time();
                $begin = $end - $metaData['maxAgeSeconds'];
                $valueKeyPrefix = $this->valueKey(array_merge($metaData, ['labelValues' => $decodedLabelValues]));

                $sampleCountKeysToRetrieve = [];
                for ($ts = $begin; $ts <= $end; $ts++) {
                    $sampleCountKeysToRetrieve[] = $valueKeyPrefix . ':' . $ts . ':observations';
                }
                $sampleCounts = apcu_fetch($sampleCountKeysToRetrieve);
                unset($sampleCountKeysToRetrieve);
                if (is_array($sampleCounts)) {
                    foreach ($sampleCounts as $k => $sampleCountThisSecond) {
                        $tstamp = explode(':', $k)[5];
                        $sampleKeysToRetrieve = [];
                        for ($i = 0; $i < $sampleCountThisSecond; $i++) {
                            $sampleKeysToRetrieve[] = $valueKeyPrefix . ':' . $tstamp . '.' . $i;
                        }
                        $newSamples = apcu_fetch($sampleKeysToRetrieve);
                        unset($sampleKeysToRetrieve);
                        if (is_array($newSamples)) {
                            $samples = array_merge($samples, $newSamples);
                        }
                    }
                }
                unset($sampleCounts);

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
     * @param int|float $val
     */
    private function incrementKeyWithValue(string $key, $val): void
    {
        $converted = $this->convertToIncrementalInteger($val);

        $this->doIncrementKeyWithValue($key, $converted);
    }

    private function doIncrementKeyWithValue(string $key, int $val): void
    {
        if ($val === 0) {
            return;
        }

        $loops = 0;

        do {
            $loops++;
            $success = apcu_inc($key, $val);
        } while ($success === false && $loops <= self::MAX_LOOPS); /** @phpstan-ignore-line */

        if ($success === false) { /** @phpstan-ignore-line */
            throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
        }
    }

    /**
     * @param int|float $val
     */
    private function decrementKeyWithValue(string $key, $val): void
    {
        if ($val === 0 || $val === 0.0) {
            return;
        }

        $converted = $this->convertToIncrementalInteger($val);
        $loops = 0;

        do {
            $loops++;
            $success = apcu_dec($key, $converted);
        } while ($success === false && $loops <= self::MAX_LOOPS); /** @phpstan-ignore-line */

        if ($success === false) { /** @phpstan-ignore-line */
            throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
        }
    }

    /**
     * @param int|float $val
     */
    private function convertToIncrementalInteger($val): int
    {
        return intval($val * $this->precisionMultiplier);
    }

    private function convertIncrementalIntegerToFloat(int $val): float
    {
        return floatval((float) $val / (float) $this->precisionMultiplier);
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

    /**
     * @param string $keyString
     * @return string
     */
    private function encodeLabelKey(string $keyString): string
    {
        return base64_encode($keyString);
    }

    /**
     * @param string $str
     * @return string
     * @throws RuntimeException
     */
    private function decodeLabelKey(string $str): string
    {
        $decodedKey = base64_decode($str, true);
        if (false === $decodedKey) {
            throw new RuntimeException('Cannot base64 decode label key');
        }
        return $decodedKey;
    }

    /**
     * @param mixed[] $data
     */
    private function storeMetadata(array $data, bool $encoded = true): void
    {
        $metaKey = $this->metaKey($data);
        if (apcu_exists($metaKey)) {
            return;
        }

        $metaData = $this->metaData($data);
        $toStore = $metaData;

        if ($encoded) {
            $toStore = json_encode($metaData);
        }

        $stored = apcu_add($metaKey, $toStore, 0);

        if (!$stored) {
            return;
        }

        apcu_add($this->metaInfoCounterKey, 0, 0);
        $counter = apcu_inc($this->metaInfoCounterKey);

        $newCountedMetricKey = $this->metaCounterKey($counter);
        apcu_store($newCountedMetricKey, $metaKey, 0);
    }

    private function metaCounterKey(int $counter): string
    {
        return str_replace('#COUNTER#', (string) $counter, $this->metaInfoCountedMetricKeyPattern);
    }
}
