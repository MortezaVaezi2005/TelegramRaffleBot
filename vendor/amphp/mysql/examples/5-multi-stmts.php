<?php

require 'support/bootstrap.php';
require 'support/generic-table.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;

$db = new MysqlConnectionPool(MysqlConfig::fromAuthority(DB_HOST, DB_USER, DB_PASS, DB_NAME));

/* create same table than in 3-generic-with-yield.php */
createGenericTable($db);

/* multi statements are enabled by default, but generally stored procedures also might return multiple resultsets anyway */
$result = $db->query("SELECT a + b FROM tmp; SELECT a - b FROM tmp;");

$i = 0;
do {
    print PHP_EOL . "Query " . ++$i . " Results:" . PHP_EOL;
    foreach ($result as $row) {
        \var_dump($row);
    }
} while ($result = $result->getNextResult()); // Advances to the next result set.

$db->query("DROP TABLE tmp");

$db->close();
