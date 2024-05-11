<?php

require __DIR__ . '/../../vendor/autoload.php';

//define('MYSQL_DEBUG', true);

/*
 * This file is not in VCS - create it if you want to set
 * the DB credentials without having them commited to VCS.
 */
@include_once __DIR__ . "/../../mysql-config.php";

if (!\defined('DB_HOST') || !\defined('DB_USER') || !\defined('DB_PASS') || !\defined('DB_NAME')) {
    print "We couldn't find a mysql-config.php." . PHP_EOL;

    ask_whether_config_should_be_created:
    print "Do you want to create a configuration to run the examples? (yes/no): ";

    $answer = \trim(\fgets(STDIN));

    if ($answer === "no") {
        print "Can't run any examples without valid database credentials." . PHP_EOL;
        exit(1);
    } elseif ($answer === "yes") {
        print "Database host: ";
        $host = \var_export(\trim(\fgets(STDIN)), true);

        print "Database user: ";
        $user = \var_export(\trim(\fgets(STDIN)), true);

        print "Database password: ";
        $pass = \var_export(\trim(\fgets(STDIN)), true);

        print "Database name: ";
        $name = \var_export(\trim(\fgets(STDIN)), true);

        $config = <<<CONFIG
<?php

const DB_HOST = $host;
const DB_USER = $user;
const DB_PASS = $pass;
const DB_NAME = $name;

CONFIG;

        \file_put_contents(__DIR__ . "/../../mysql-config.php", $config);
        require __DIR__ . "/../../mysql-config.php";

        print "Successfully created configuration, running example now." . PHP_EOL;
        print "You can find the config in " . \realpath(__DIR__ . "/../../mysql-config.php") . PHP_EOL;
        print \str_repeat("-", 80) . PHP_EOL;
    } else {
        goto ask_whether_config_should_be_created;
    }
}
