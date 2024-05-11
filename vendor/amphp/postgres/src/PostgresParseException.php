<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlException;

final class PostgresParseException extends SqlException
{
    public function __construct(string $message = '')
    {
        $message = "Parse error while splitting array" . (($message === '') ? '' : ": " . $message);
        parent::__construct($message);
    }
}
