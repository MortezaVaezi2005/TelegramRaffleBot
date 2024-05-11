# amphp/postgres

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/postgres` is an asynchronous Postgres client.
The library implements concurrent querying by transparently distributing queries across a scalable pool of available connections. Either [ext-pgsql](https://secure.php.net/pgsql) (bundled with PHP) or [pecl-pq](https://pecl.php.net/package/pq) are required.

## Features

- Exposes a non-blocking API for issuing multiple Postgres queries concurrently
- Transparent connection pooling to overcome Postgres' fundamentally synchronous connection protocol
- Support for parameterized prepared statements
- Nested transactions with commit and rollback event hooks
- Unbuffered results to reduce memory usage for large result sets
- Support for sending and receiving notifications

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/postgres
```

## Requirements

- PHP 8.1+
- [ext-pgsql](https://secure.php.net/pgsql) or [pecl-pq](https://pecl.php.net/package/pq)

Note: [pecl-ev](https://pecl.php.net/package/ev) is not compatible with ext-pgsql. If you wish to use pecl-ev for the event loop backend, you must use pecl-pq.

## Documentation & Examples

Prepared statements and parameterized queries support named placeholders, as well as `?` and standard numeric (i.e. `$1`) placeholders.

Row values are cast to their corresponding PHP types. For example, integer columns will be an `int` in the result row array.

More examples can be found in the [`examples`](examples) directory.

```php
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;

$config = PostgresConfig::fromString("host=localhost user=postgres db=test");

$pool = new PostgresConnectionPool($config);

$statement = $pool->prepare("SELECT * FROM test WHERE id = :id");

$result = $statement->execute(['id' => 1337]);
foreach ($result as $row) {
    // $row is an associative-array of column values, e.g.: $row['column_name']
}
```

## Versioning

`amphp/postgres` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
