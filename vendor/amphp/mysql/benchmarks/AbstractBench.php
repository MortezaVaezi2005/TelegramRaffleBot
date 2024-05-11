<?php

namespace Amp\Mysql\Bench;

/**
 * docker run --rm -ti -e MYSQL_ROOT_PASSWORD=secret -p 10101:3306 mysql:latest --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
 */
class AbstractBench
{
    protected string $host = 'localhost:10101';

    protected string $user = 'root';

    protected string $password = 'secret';
}
