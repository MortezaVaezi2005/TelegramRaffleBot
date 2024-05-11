<?php declare(strict_types=1);

namespace Amp\Http;

use const Amp\Http\Internal\HEADER_LOWERCASE_MAP;

/**
 * Base class for HTTP request and response messages.
 *
 * @psalm-type HeaderPairsType = list<array{non-empty-string, string}>
 * @psalm-type HeaderParamValueType = string|array<string>
 * @psalm-type HeaderParamArrayType = array<non-empty-string, HeaderParamValueType>
 * @psalm-type HeaderMapType = array<non-empty-string, list<string>>
 */
abstract class HttpMessage
{
    /** @var HeaderMapType */
    private array $headers = [];

    /** @var array<non-empty-string, list<non-empty-string>> */
    private array $headerCase = [];

    /**
     * Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers
     * have been set.
     *
     * @return HeaderMapType
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns the headers as list of [field, name] pairs in the original casing provided by the application or server.
     *
     * @return HeaderPairsType
     */
    final public function getHeaderPairs(): array
    {
        $headers = [];

        foreach ($this->headers as $lcName => $values) {
            $size = \count($values);

            for ($i = 0; $i < $size; $i++) {
                $headers[] = [$this->headerCase[$lcName][$i], $values[$i]];
            }
        }

        return $headers;
    }

    /**
     * Returns the array of values for the given header or an empty array if the header does not exist.
     *
     * @return list<string>
     *
     * @psalm-suppress InvalidArrayOffset Using an empty string will not cause an error.
     */
    public function getHeaderArray(string $name): array
    {
        return $this->headers[HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name)] ?? [];
    }

    /**
     * Returns the value of the given header. If multiple headers are present for the named header, only the first
     * header value will be returned. Use getHeaderArray() to return an array of all values for the particular header.
     * Returns null if the header does not exist.
     *
     * @psalm-suppress InvalidArrayOffset Using an empty string will not cause an error.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name)][0] ?? null;
    }

    /**
     * Removes all current headers and sets new headers from the given array.
     *
     * @param HeaderParamArrayType $headers
     */
    protected function setHeaders(array $headers): void
    {
        // Ensure this is an atomic operation, either all headers are set or none.
        $before = $this->headers;
        $beforeCase = $this->headerCase;

        $this->headers = [];
        $this->headerCase = [];

        try {
            $this->setHeadersFromArray($headers);
        } catch (\Throwable $e) {
            $this->headers = $before;
            $this->headerCase = $beforeCase;

            throw $e;
        }
    }

    /**
     * Replaces headers from the given array. Header names not contained in the array are not changed.
     *
     * @param HeaderParamArrayType $headers
     */
    protected function replaceHeaders(array $headers): void
    {
        // Ensure this is an atomic operation, either all given headers are replaced or none.
        $before = $this->headers;
        $beforeCase = $this->headerCase;

        try {
            $this->setHeadersFromArray($headers);
        } catch (\Throwable $e) {
            $this->headers = $before;
            $this->headerCase = $beforeCase;

            throw $e;
        }
    }

    /**
     * @param HeaderParamArrayType $headers
     */
    private function setHeadersFromArray(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (!\is_string($value) && !\is_array($value)) {
                $value = self::castHeaderValue($value);
            }

            $this->setHeader($name, $value);
        }
    }

    /**
     * Sets the named header to the given value.
     *
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    protected function setHeader(string $name, array|string $value): void
    {
        \assert($this->isNameValid($name), "Invalid header name");

        $lcName = HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name);

        if (!\is_array($value)) {
            \assert(self::isValueValid([$value]), "Invalid header value");
            $this->headers[$lcName] = [$value];
            $this->headerCase[$lcName] = [$name];
            return;
        }

        if (!$value) {
            $this->removeHeader($name);
            return;
        }

        $value = self::castHeaderArrayValues($value);

        \assert(self::isValueValid($value), "Invalid header value");

        $this->headers[$lcName] = $value;
        $this->headerCase[$lcName] = \array_fill(0, \count($value), $name);
    }

    /**
     * Adds the value to the named header, or creates the header with the given value if it did not exist.
     *
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    protected function addHeader(string $name, array|string $value): void
    {
        \assert($this->isNameValid($name), "Invalid header name");

        $lcName = HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name);

        if (!\is_array($value)) {
            \assert(self::isValueValid([$value]), "Invalid header value");
            $this->headers[$lcName][] = $value;
            $this->headerCase[$lcName][] = $name;
            return;
        }

        $value = self::castHeaderArrayValues($value);

        \assert(self::isValueValid($value), "Invalid header value");

        foreach ($value as $header) {
            $this->headers[$lcName][] = $header;
            $this->headerCase[$lcName][] = $name;
        }
    }

    /**
     * Removes the given header if it exists.
     *
     * @psalm-suppress InvalidArrayOffset Using an empty string will not cause an error.
     */
    protected function removeHeader(string $name): void
    {
        $lcName = HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name);

        unset($this->headers[$lcName], $this->headerCase[$lcName]);
    }

    /**
     * Checks if given header exists.
     *
     * @psalm-suppress InvalidArrayOffset Using an empty string will not cause an error.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[HEADER_LOWERCASE_MAP[$name] ?? \strtolower($name)]);
    }

    private static function castHeaderValue(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value), $value instanceof \Stringable => (string) $value,
            default => throw new \TypeError(\sprintf(
                'Header array may contain only types which may be cast to a string; got "%s"',
                \get_debug_type($value),
            )),
        };
    }

    /**
     * @param array<int|float|string> $values
     * @return list<string>
     */
    private static function castHeaderArrayValues(array $values): array
    {
        static $mapper;

        return \array_map($mapper ??= self::castHeaderValue(...), \array_values($values));
    }

    private function isNameValid(string $name): bool
    {
        return (bool) \preg_match('/^[A-Za-z0-9`~!#$%^&_|\'\-*+.]+$/', $name);
    }

    /**
     * Determines if the given value is a valid header value.
     *
     * @param list<string> $values
     *
     * @throws \Error If the given value cannot be converted to a string and is not an array of values that can be
     *     converted to strings.
     */
    private static function isValueValid(array $values): bool
    {
        foreach ($values as $value) {
            if (\preg_match("/[^\t\r\n\x20-\x7e\x80-\xfe]|\r\n/", $value)) {
                return false;
            }
        }

        return true;
    }
}
