<?php

require 'support/bootstrap.php';
require 'support/generic-table.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;

$db = new MysqlConnectionPool(MysqlConfig::fromAuthority(DB_HOST, DB_USER, DB_PASS, DB_NAME));

/* create same table than in 3-generic-with-yield.php */
createGenericTable($db);

$transaction = $db->beginTransaction();

$transaction->execute("INSERT INTO tmp VALUES (?, ? * 2)", [6, 6]);

$result = $transaction->execute("SELECT * FROM tmp WHERE a >= ?", [5]); // Two rows should be returned.

foreach ($result as $row) {
    \var_dump($row);
}

$transaction->rollback();

// Run same query again, should only return a single row since the other was rolled back.
$result = $db->execute("SELECT * FROM tmp WHERE a >= ?", [5]);

foreach ($result as $row) {
    \var_dump($row);
}

$db->close();
