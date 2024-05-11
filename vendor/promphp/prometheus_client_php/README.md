# A prometheus client library written in PHP

![Tests](https://github.com/promphp/prometheus_client_php/workflows/Tests/badge.svg)

This library uses Redis or APCu to do the client side aggregation.
If using Redis, we recommend running a local Redis instance next to your PHP workers.

## How does it work?

Usually PHP worker processes don't share any state.
You can pick from four adapters.
Redis, APC, APCng, or an in-memory adapter.
While the first needs a separate binary running, the second and third just need the [APC](https://pecl.php.net/package/APCU) extension to be installed. If you don't need persistent metrics between requests (e.g. a long running cron job or script) the in-memory adapter might be suitable to use.

## Installation

Add as [Composer](https://getcomposer.org/) dependency:

```sh
composer require promphp/prometheus_client_php
```

## Usage

A simple counter:
```php
\Prometheus\CollectorRegistry::getDefault()
    ->getOrRegisterCounter('', 'some_quick_counter', 'just a quick measurement')
    ->inc();
```

Write some enhanced metrics:
```php
$registry = \Prometheus\CollectorRegistry::getDefault();

$counter = $registry->getOrRegisterCounter('test', 'some_counter', 'it increases', ['type']);
$counter->incBy(3, ['blue']);

$gauge = $registry->getOrRegisterGauge('test', 'some_gauge', 'it sets', ['type']);
$gauge->set(2.5, ['blue']);

$histogram = $registry->getOrRegisterHistogram('test', 'some_histogram', 'it observes', ['type'], [0.1, 1, 2, 3.5, 4, 5, 6, 7, 8, 9]);
$histogram->observe(3.5, ['blue']);

$summary = $registry->getOrRegisterSummary('test', 'some_summary', 'it observes a sliding window', ['type'], 84600, [0.01, 0.05, 0.5, 0.95, 0.99]);
$summary->observe(5, ['blue']);
```

Manually register and retrieve metrics (these steps are combined in the `getOrRegister...` methods):
```php
$registry = \Prometheus\CollectorRegistry::getDefault();

$counterA = $registry->registerCounter('test', 'some_counter', 'it increases', ['type']);
$counterA->incBy(3, ['blue']);

// once a metric is registered, it can be retrieved using e.g. getCounter:
$counterB = $registry->getCounter('test', 'some_counter')
$counterB->incBy(2, ['red']);
```

Expose the metrics:
```php
$registry = \Prometheus\CollectorRegistry::getDefault();

$renderer = new RenderTextFormat();
$result = $renderer->render($registry->getMetricFamilySamples());

header('Content-type: ' . RenderTextFormat::MIME_TYPE);
echo $result;
```

Change the Redis options (the example shows the defaults):
```php
\Prometheus\Storage\Redis::setDefaultOptions(
    [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'timeout' => 0.1, // in seconds
        'read_timeout' => '10', // in seconds
        'persistent_connections' => false
    ]
);
```

Using the InMemory storage:
```php
$registry = new CollectorRegistry(new InMemory());

$counter = $registry->registerCounter('test', 'some_counter', 'it increases', ['type']);
$counter->incBy(3, ['blue']);

$renderer = new RenderTextFormat();
$result = $renderer->render($registry->getMetricFamilySamples());
```

Using the APC or APCng storage:
```php
$registry = new CollectorRegistry(new APCng());
 or
$registry = new CollectorRegistry(new APC());
```
(see the `README.APCng.md` file for more details)


### Advanced Usage

#### Advanced Histogram Usage
On passing an empty array for the bucket parameter on instantiation, a set of default buckets will be used instead.
Whilst this is a good base for a typical web application, there is named constructor to assist in the generation of
exponential / geometric buckets.

Eg:
```
Histogram::exponentialBuckets(0.05, 1.5, 10);
```

This will start your buckets with a value of 0.05, grow them by a factor of 1.5 per bucket across a set of 10 buckets.

Also look at the [examples](examples).

#### PushGateway Support
As of Version 2.0.0 this library doesn't support the Prometheus PushGateway anymore because we want to have this package as small als possible. If you need Prometheus PushGateway support, you could use the companion library:  https://github.com/PromPHP/prometheus_push_gateway_php
```
composer require promphp/prometheus_push_gateway_php
```

## Development

### Dependencies

* PHP ^7.2 | ^8.0
* PHP Redis extension
* PHP APCu extension
* [Composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx)
* Redis

Start a Redis instance:
```
docker-compose up redis
```

Run the tests:
```
composer install

# when Redis is not listening on localhost:
# export REDIS_HOST=192.168.59.100
./vendor/bin/phpunit
```

## Black box testing

Just start the nginx, fpm & Redis setup with docker-compose:
```
docker-compose up
```
Pick the adapter you want to test.

```
docker-compose run phpunit env ADAPTER=apc vendor/bin/phpunit tests/Test/
docker-compose run phpunit env ADAPTER=apcng vendor/bin/phpunit tests/Test/
docker-compose run phpunit env ADAPTER=redis vendor/bin/phpunit tests/Test/
```

## Performance testing

This currently tests the APC and APCng adapters head-to-head and reports if the APCng adapter is slower for any actions.
```
phpunit vendor/bin/phpunit tests/Test/ --group Performance
```

The test can also be run inside a container.
```
docker-compose up
docker-compose run phpunit vendor/bin/phpunit tests/Test/ --group Performance
```
