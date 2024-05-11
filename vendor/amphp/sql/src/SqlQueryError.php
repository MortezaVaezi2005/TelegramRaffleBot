<?php declare(strict_types=1);

namespace Amp\Sql;

class SqlQueryError extends \Error
{
    public function __construct(
        string $message,
        protected readonly string $query = "",
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    final public function getQuery(): string
    {
        return $this->query;
    }

    public function __toString(): string
    {
        if ($this->query === "") {
            return parent::__toString();
        }

        $msg = $this->message;
        $this->message .= "\nCurrent query was {$this->query}";
        $str = parent::__toString();
        $this->message = $msg;
        return $str;
    }
}
