#!/usr/bin/env php
<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\Postgres;

$config = Postgres\PostgresConfig::fromString('host=localhost user=postgres');

$connection = Postgres\connect($config);

$result = $connection->query('SHOW ALL');

foreach ($result as $row) {
    printf("%-35s = %s (%s)\n", $row['name'], $row['setting'], $row['description']);
}
