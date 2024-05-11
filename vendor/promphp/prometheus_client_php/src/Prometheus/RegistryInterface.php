<?php

namespace Prometheus;

use Prometheus\Exception\MetricNotFoundException;
use Prometheus\Exception\MetricsRegistrationException;

interface RegistryInterface
{
    /**
     * Removes all previously stored metrics from underlying storage adapter
     *
     * @return void
     */
    public function wipeStorage(): void;

    /**
     * @return MetricFamilySamples[]
     */
    public function getMetricFamilySamples(): array;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. duration_seconds
     * @param string   $help e.g. The duration something took in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @return Gauge
     * @throws MetricsRegistrationException
     */
    public function registerGauge(string $namespace, string $name, string $help, array $labels = []): Gauge;

    /**
     * @param string $namespace
     * @param string $name
     *
     * @return Gauge
     * @throws MetricNotFoundException
     */
    public function getGauge(string $namespace, string $name): Gauge;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. duration_seconds
     * @param string   $help e.g. The duration something took in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @return Gauge
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterGauge(string $namespace, string $name, string $help, array $labels = []): Gauge;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. requests
     * @param string   $help e.g. The number of requests made.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @return Counter
     * @throws MetricsRegistrationException
     */
    public function registerCounter(string $namespace, string $name, string $help, array $labels = []): Counter;

    /**
     * @param string $namespace
     * @param string $name
     *
     * @return Counter
     * @throws MetricNotFoundException
     */
    public function getCounter(string $namespace, string $name): Counter;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. requests
     * @param string   $help e.g. The number of requests made.
     * @param string[] $labels e.g. ['controller', 'action']
     *
     * @return Counter
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterCounter(string $namespace, string $name, string $help, array $labels = []): Counter;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. duration_seconds
     * @param string   $help e.g. A histogram of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param float[]    $buckets e.g. [100, 200, 300]
     *
     * @return Histogram
     * @throws MetricsRegistrationException
     */
    public function registerHistogram(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        array $buckets = null
    ): Histogram;

    /**
     * @param string $namespace
     * @param string $name
     *
     * @return Histogram
     * @throws MetricNotFoundException
     */
    public function getHistogram(string $namespace, string $name): Histogram;

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A histogram of the duration in seconds.
     * @param string[]  $labels e.g. ['controller', 'action']
     * @param float[]  $buckets e.g. [100, 200, 300]
     *
     * @return Histogram
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterHistogram(string $namespace, string $name, string $help, array $labels = [], array $buckets = null): Histogram;

    /**
     * @param string   $namespace e.g. cms
     * @param string   $name e.g. duration_seconds
     * @param string   $help e.g. A histogram of the duration in seconds.
     * @param string[] $labels e.g. ['controller', 'action']
     * @param int $maxAgeSeconds e.g. 604800
     * @param float[]|null $quantiles e.g. [0.01, 0.5, 0.99]
     *
     * @return Summary
     * @throws MetricsRegistrationException
     */
    public function registerSummary(
        string $namespace,
        string $name,
        string $help,
        array $labels = [],
        int $maxAgeSeconds = 86400,
        array $quantiles = null
    ): Summary;

    /**
     * @param string $namespace
     * @param string $name
     *
     * @return Summary
     * @throws MetricNotFoundException
     */
    public function getSummary(string $namespace, string $name): Summary;

    /**
     * @param string $namespace e.g. cms
     * @param string $name e.g. duration_seconds
     * @param string $help e.g. A histogram of the duration in seconds.
     * @param string[]  $labels e.g. ['controller', 'action']
     * @param int $maxAgeSeconds e.g. 604800
     * @param float[]|null $quantiles e.g. [0.01, 0.5, 0.99]
     *
     * @return Summary
     * @throws MetricsRegistrationException
     */
    public function getOrRegisterSummary(string $namespace, string $name, string $help, array $labels = [], int $maxAgeSeconds = 86400, array $quantiles = null): Summary;
}
