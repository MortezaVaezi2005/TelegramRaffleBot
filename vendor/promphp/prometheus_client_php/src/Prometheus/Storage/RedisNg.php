<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Exception\StorageException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use Prometheus\Summary;
use RuntimeException;

class RedisNg implements Adapter
{
    const PROMETHEUS_METRIC_KEYS_SUFFIX = '_METRIC_KEYS';

    /**
     * @var mixed[]
     */
    private static $defaultOptions = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'timeout' => 0.1,
        'read_timeout' => '10',
        'persistent_connections' => false,
        'password' => null,
    ];

    /**
     * @var string
     */
    private static $prefix = 'PROMETHEUS_';

    /**
     * @var mixed[]
     */
    private $options = [];

    /**
     * @var \Redis
     */
    private $redis;

    /**
     * @var boolean
     */
    private $connectionInitialized = false;

    /**
     * Redis constructor.
     * @param mixed[] $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::$defaultOptions, $options);
        $this->redis = new \Redis();
    }

    /**
     * @param \Redis $redis
     * @return self
     * @throws StorageException
     */
    public static function fromExistingConnection(\Redis $redis): self
    {
        if ($redis->isConnected() === false) {
            throw new StorageException('Connection to Redis server not established');
        }

        $self = new self();
        $self->connectionInitialized = true;
        $self->redis = $redis;

        return $self;
    }

    /**
     * @param mixed[] $options
     */
    public static function setDefaultOptions(array $options): void
    {
        self::$defaultOptions = array_merge(self::$defaultOptions, $options);
    }

    /**
     * @param string $prefix
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @throws StorageException
     * @deprecated use replacement method wipeStorage from Adapter interface
     */
    public function flushRedis(): void
    {
        $this->wipeStorage();
    }

    /**
     * @inheritDoc
     */
    public function wipeStorage(): void
    {
        $this->ensureOpenConnection();

        $searchPattern = "";

        $globalPrefix = $this->redis->getOption(\Redis::OPT_PREFIX);
        // @phpstan-ignore-next-line false positive, phpstan thinks getOptions returns int
        if (is_string($globalPrefix)) {
            $searchPattern .= $globalPrefix;
        }

        $searchPattern .= self::$prefix;
        $searchPattern .= '*';

        $this->redis->eval(
            <<<LUA
redis.replicate_commands()
local cursor = "0"
repeat 
    local results = redis.call('SCAN', cursor, 'MATCH', ARGV[1])
    cursor = results[1]
    for _, key in ipairs(results[2]) do
        redis.call('DEL', key)
    end
until cursor == "0"
LUA
            ,
            [$searchPattern],
            0
        );
    }

    /**
     * @param mixed[] $data
     *
     * @return string
     */
    private function metaKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            'meta'
        ]);
    }

    /**
     * @param mixed[] $data
     *
     * @return string
     */
    private function valueKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value'
        ]);
    }

    /**
     * @return MetricFamilySamples[]
     * @throws StorageException
     */
    public function collect(bool $sortMetrics = true): array
    {
        $this->ensureOpenConnection();
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges($sortMetrics));
        $metrics = array_merge($metrics, $this->collectCounters($sortMetrics));
        $metrics = array_merge($metrics, $this->collectSummaries());
        return array_map(
            function (array $metric): MetricFamilySamples {
                return new MetricFamilySamples($metric);
            },
            $metrics
        );
    }

    /**
     * @throws StorageException
     */
    private function ensureOpenConnection(): void
    {
        if ($this->connectionInitialized === true) {
            return;
        }

        $this->connectToServer();

        if ($this->options['password'] !== null) {
            $this->redis->auth($this->options['password']);
        }

        if (isset($this->options['database'])) {
            $this->redis->select($this->options['database']);
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->options['read_timeout']);

        $this->connectionInitialized = true;
    }

    /**
     * @throws StorageException
     */
    private function connectToServer(): void
    {
        try {
            $connection_successful = false;
            if ($this->options['persistent_connections'] !== false) {
                $connection_successful = $this->redis->pconnect(
                    $this->options['host'],
                    (int)$this->options['port'],
                    (float)$this->options['timeout']
                );
            } else {
                $connection_successful = $this->redis->connect($this->options['host'], (int)$this->options['port'], (float)$this->options['timeout']);
            }
            if (!$connection_successful) {
                throw new StorageException("Can't connect to Redis server", 0);
            }
        } catch (\RedisException $e) {
            throw new StorageException("Can't connect to Redis server", 0, $e);
        }
    }

    /**
     * @param mixed[] $data
     * @throws StorageException
     */
    public function updateHistogram(array $data): void
    {
        $this->ensureOpenConnection();
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues']);

        $this->redis->eval(
            <<<LUA
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[1], ARGV[3])
redis.call('hIncrBy', KEYS[1], ARGV[2], 1)
if tonumber(result) >= tonumber(ARGV[3]) then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
    redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                json_encode(['b' => 'sum', 'labelValues' => $data['labelValues']]),
                json_encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]),
                $data['value'],
                json_encode($metaData),
            ],
            2
        );
    }

    /**
     * @param mixed[] $data
     * @throws StorageException
     */
    public function updateSummary(array $data): void
    {
        $this->ensureOpenConnection();
// store meta
        $summaryKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX;
        $summaryKeyIndexKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ":keys";
        if (!$this->redis->sIsMember($summaryKeyIndexKey, $summaryKey . ':' . $data["name"])) {
            $this->redis->sAdd($summaryKeyIndexKey, $summaryKey . ':' . $data["name"]);
        }

        $metaKey = $summaryKey . ':' . $this->metaKey($data);
        $json = json_encode($this->metaData($data));
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redis->setnx($metaKey, $json);

        // store value key
        $valueKey = $summaryKey . ':' . $this->valueKey($data);

        $json = json_encode($this->encodeLabelValues($data['labelValues']));
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        $this->redis->setnx($valueKey, $json);

        // trick to handle uniqid collision
        $done = false;
        while (!$done) {
            $sampleKey = $valueKey . ':' . uniqid('', true);
            $done = $this->redis->set($sampleKey, $data['value'], ['NX', 'EX' => $data['maxAgeSeconds']]);
            $this->redis->sAdd($summaryKey . ':' . $data["name"] . ":value:keys", $sampleKey);
        }
    }

    /**
     * @param mixed[] $data
     * @throws StorageException
     */
    public function updateGauge(array $data): void
    {
        $this->ensureOpenConnection();
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis->eval(
            <<<LUA
local result = redis.call(ARGV[1], KEYS[1], ARGV[2], ARGV[3])

if ARGV[1] == 'hSet' then
    if result == 1 then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
else
    if result == ARGV[3] then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
end
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                $this->getRedisCommand($data['command']),
                json_encode($data['labelValues']),
                $data['value'],
                json_encode($metaData),
            ],
            2
        );
    }

    /**
     * @param mixed[] $data
     * @throws StorageException
     */
    public function updateCounter(array $data): void
    {
        $this->ensureOpenConnection();
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis->eval(
            <<<LUA
local result = redis.call(ARGV[1], KEYS[1], ARGV[3], ARGV[2])
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hMSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA
            ,
            [
                $this->toMetricKey($data),
                self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                $this->getRedisCommand($data['command']),
                $data['value'],
                json_encode($data['labelValues']),
                json_encode($metaData),
            ],
            2
        );
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
     * @return mixed[]
     */
    private function collectHistograms(): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            // Add the Inf bucket so we can compute it later on
            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $k) {
                $d = json_decode($k, true);
                if ($d['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $d['labelValues'];
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map("unserialize", array_unique(array_map("serialize", $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (!isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }
        return $histograms;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function removePrefixFromKey(string $key): string  /** @phpstan-ignore-line */
    {
        // @phpstan-ignore-next-line false positive, phpstan thinks getOptions returns int
        if ($this->redis->getOption(\Redis::OPT_PREFIX) === null) {
            return $key;
        }
        // @phpstan-ignore-next-line false positive, phpstan thinks getOptions returns int
        return substr($key, strlen($this->redis->getOption(\Redis::OPT_PREFIX)));
    }

    /**
     * @return mixed[]
     */
    private function collectSummaries(): array
    {
        $math = new Math();
        $summaryKeyIndexKey = self::$prefix . Summary::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX . ":keys";

        $keys = $this->redis->sMembers($summaryKeyIndexKey);
        $summaries = [];
        foreach ($keys as $metaKey) {
            $rawSummary = $this->redis->get($metaKey . ':meta');
            if ($rawSummary === false) {
                continue;
            }
            $summary = json_decode($rawSummary, true);
            $metaData = $summary;
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'maxAgeSeconds' => $metaData['maxAgeSeconds'],
                'quantiles' => $metaData['quantiles'],
                'samples' => [],
            ];
            $values = $this->redis->sMembers($metaKey . ':value:keys');
            $samples = [];
            foreach ($values as $valueKey) {
                $rawValue = explode(":", $valueKey);
                if ($rawValue === false) {
                    continue;
                }
                $encodedLabelValues = $rawValue[2];
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);

                $return = $this->redis->get($valueKey);
                if ($return !== false) {
                    $samples[] = (float)$return;
                }
            }
            if (count($samples) === 0) {
                if (isset($valueKey)) {
                    $this->redis->del($valueKey);
                }

                continue;
            }

            assert(isset($decodedLabelValues));

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


            $summaries[] = $data;
        }
        return $summaries;
    }

    /**
     * @return mixed[]
     */
    private function collectGauges(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name' => $gauge['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }

            if ($sortMetrics) {
                usort($gauge['samples'], function ($a, $b): int {
                    return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
                });
            }

            $gauges[] = $gauge;
        }
        return $gauges;
    }

    /**
     * @return mixed[]
     */
    private function collectCounters(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            $counter = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name' => $counter['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }

            if ($sortMetrics) {
                usort($counter['samples'], function ($a, $b): int {
                    return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
                });
            }

            $counters[] = $counter;
        }
        return $counters;
    }

    /**
     * @param int $cmd
     * @return string
     */
    private function getRedisCommand(int $cmd): string
    {
        switch ($cmd) {
            case Adapter::COMMAND_INCREMENT_INTEGER:
                return 'hIncrBy';
            case Adapter::COMMAND_INCREMENT_FLOAT:
                return 'hIncrByFloat';
            case Adapter::COMMAND_SET:
                return 'hSet';
            default:
                throw new InvalidArgumentException("Unknown command");
        }
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function toMetricKey(array $data): string
    {
        return implode(':', [self::$prefix, $data['type'], $data['name']]);
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
