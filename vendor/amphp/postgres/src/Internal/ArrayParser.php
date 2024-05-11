<?php declare(strict_types=1);

namespace Amp\Postgres\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Postgres\PostgresParseException;

/**
 * @internal
 */
final class ArrayParser
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param string $data String representation of PostgresSQL array.
     * @param \Closure(string):mixed $cast Callback to cast parsed values.
     * @param string $delimiter Delimiter used to separate values.
     *
     * @return list<mixed> Parsed column data.
     *
     * @throws PostgresParseException
     */
    public static function parse(string $data, \Closure $cast, string $delimiter = ','): array
    {
        $data = \trim($data);

        $parser = (new self($data, $cast, $delimiter))->parser();
        $data = \iterator_to_array($parser, false);

        if ($parser->getReturn() !== '') {
            throw new PostgresParseException("Data left in buffer after parsing");
        }

        return $data;
    }

    /**
     * @param string $data String representation of PostgresSQL array.
     * @param \Closure(string):mixed $cast Callback to cast parsed values.
     * @param string $delimiter Delimiter used to separate values.
     */
    private function __construct(
        private string $data,
        private readonly \Closure $cast,
        private readonly string $delimiter = ',',
    ) {
    }

    /**
     * Recursive generator parser yielding array values.
     *
     * @throws PostgresParseException
     */
    private function parser(): \Generator
    {
        if ($this->data === '') {
            throw new PostgresParseException("Unexpected end of data");
        }

        if ($this->data[0] !== '{') {
            throw new PostgresParseException("Missing opening bracket");
        }

        $this->data = \ltrim(\substr($this->data, 1));

        do {
            if ($this->data === '') {
                throw new PostgresParseException("Unexpected end of data");
            }

            if ($this->data[0] === '}') { // Empty array
                return \ltrim(\substr($this->data, 1));
            }

            if ($this->data[0] === '{') { // Array
                $parser = (new self($this->data, $this->cast, $this->delimiter))->parser();
                yield \iterator_to_array($parser, false);
                $this->data = $parser->getReturn();
                $end = $this->trim(0);
                continue;
            }

            if ($this->data[0] === '"') { // Quoted value
                for ($position = 1; isset($this->data[$position]); ++$position) {
                    if ($this->data[$position] === '\\') {
                        ++$position; // Skip next character
                        continue;
                    }

                    if ($this->data[$position] === '"') {
                        break;
                    }
                }

                if (!isset($this->data[$position])) {
                    throw new PostgresParseException("Could not find matching quote in quoted value");
                }

                $yield = \stripslashes(\substr($this->data, 1, $position - 1));

                $end = $this->trim($position + 1);
            } else { // Unquoted value
                $position = 0;
                while (isset($this->data[$position]) && $this->data[$position] !== $this->delimiter && $this->data[$position] !== '}') {
                    ++$position;
                }

                $yield = \trim(\substr($this->data, 0, $position));

                $end = $this->trim($position);

                if (\strcasecmp($yield, "NULL") === 0) { // Literal NULL is always unquoted.
                    yield null;
                    continue;
                }
            }

            yield ($this->cast)($yield);
        } while ($end !== '}');

        return $this->data;
    }

    /**
     * @param int $position Position to start search for delimiter.
     *
     * @return string First non-whitespace character after given position.
     *
     * @throws PostgresParseException
     */
    private function trim(int $position): string
    {
        $this->data = \ltrim(\substr($this->data, $position));

        if ($this->data === '') {
            throw new PostgresParseException("Unexpected end of data");
        }

        $end = $this->data[0];

        if ($end !== $this->delimiter && $end !== '}') {
            throw new PostgresParseException("Invalid delimiter");
        }

        $this->data = \ltrim(\substr($this->data, 1));

        return $end;
    }
}
