<?php

require 'support/bootstrap.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;

$db = new MysqlConnectionPool(MysqlConfig::fromAuthority(DB_HOST, DB_USER, DB_PASS, DB_NAME));

$result = $db->query("SELECT 1 AS value");

foreach ($result as $row) {
    \var_dump($row['value']);
}

$db->close();
