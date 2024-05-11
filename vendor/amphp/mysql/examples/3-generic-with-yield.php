<?php

require 'support/bootstrap.php';

use Amp\Future;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use function Amp\async;

$db = new MysqlConnectionPool(MysqlConfig::fromAuthority(DB_HOST, DB_USER, DB_PASS, DB_NAME));

$db->query("DROP TABLE IF EXISTS tmp");

/* Create table and insert a few rows */
/* we need to wait until table is finished, so that we can insert. */
$db->query("CREATE TABLE IF NOT EXISTS tmp (a INT(10), b INT(10))");

print "Table successfully created." . PHP_EOL;

$statement = $db->prepare("INSERT INTO tmp (a, b) VALUES (?, ? * 2)");

$future = [];
foreach (\range(1, 5) as $num) {
    $future[] = async(fn () => $statement->execute([$num, $num]));
}

/* wait until everything is inserted */
$results = Future\await($future);

print "Insertion successful (if it wasn't, an exception would have been thrown by now)" . PHP_EOL;

$result = $db->query("SELECT a, b FROM tmp");

foreach ($result as $row) {
    var_dump($row);
}

$db->query("DROP TABLE tmp");

$db->close();
