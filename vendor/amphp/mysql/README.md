# amphp/mysql

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/mysql` is an asynchronous MySQL client.
The library implements concurrent querying by transparently distributing queries across a scalable pool of available connections. The client transparently distributes these queries across a scalable pool of available connections and does so using 100% userland PHP; there are *no external extension dependencies* (e.g. `ext/mysqli`, `ext/pdo`, etc.).

## Features

- Exposes a non-blocking API for issuing multiple MySQL queries concurrently
- Transparent connection pooling to overcome MySQL's fundamentally synchronous connection protocol
- MySQL transfer encoding support (gzip, TLS encryption)
- Support for parameterized prepared statements
- Nested transactions with commit and rollback event hooks
- Unbuffered results to reduce memory usage for large result sets
- *Full* MySQL protocol support including *all*<sup>†</sup> available commands asynchronously

<sup>† As documented in [official Mysql Internals Manual](https://dev.mysql.com/doc/internals/en/client-server-protocol.html)</sup>

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/mysql
```

## Requirements

- PHP 8.1+

## Usage

More extensive code examples reside in the [`examples`](examples) directory.

```php
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;

$config = MysqlConfig::fromString(
    "host=localhost user=username password=password db=test"
);

$pool = new MysqlConnectionPool($config);

$statement = $pool->prepare("SELECT * FROM table_name WHERE id = :id");

$result = $statement->execute(['id' => 1337]);
foreach ($result as $row) {
    // $row is an associative-array of column values, e.g.: $row['column_name']
}
```

## Versioning

`amphp/mysql` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
