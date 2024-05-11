<?php

/*
 * Generic example for establishing a connection
 */

require 'support/bootstrap.php';

use Amp\Mysql;

$config = Mysql\MysqlConfig::fromAuthority(DB_HOST, DB_USER, DB_PASS, DB_NAME);

/* use an alternative charset... Default is utf8mb4_general_ci */
$config = $config->withCharset("ascii", "ascii_general_ci");

$db = Mysql\connect($config);

echo "Character set changed\n";

/* optional, as connection will automatically close when destructed. */
$db->close();
