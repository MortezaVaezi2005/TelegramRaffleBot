#!/usr/bin/env php
<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;

$config = PostgresConfig::fromString('host=localhost user=postgres');
$pool = new PostgresConnectionPool($config);

$pool->query('DROP TABLE IF EXISTS test');

$transaction = $pool->beginTransaction();

$transaction->query('CREATE TABLE test (domain VARCHAR(63), tld VARCHAR(63), PRIMARY KEY (domain, tld))');

$statement = $transaction->prepare('INSERT INTO test VALUES (?, ?)');

$statement->execute(['amphp', 'org']);
$statement->execute(['google', 'com']);
$statement->execute(['github', 'com']);

$result = $transaction->execute('SELECT * FROM test WHERE tld = :tld', ['tld' => 'com']);

$format = "%-20s | %-10s\n";
printf($format, 'TLD', 'Domain');
foreach ($result as $row) {
    printf($format, $row['domain'], $row['tld']);
}

$transaction->rollback();

$pool->close();
