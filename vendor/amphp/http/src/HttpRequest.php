<?php declare(strict_types=1);

namespace Amp\Http;

use League\Uri\QueryString;
use Psr\Http\Message\UriInterface as PsrUri;

/**
 * @psalm-type QueryPairsType = list<array{string, string|null}>
 * @psalm-type QueryValueType = string|array<string|null>|null
 * @psalm-type QueryArrayType = array<string, QueryValueType>
 * @psalm-type QueryMapType = array<string, list<string|null>>
 */
abstract class HttpRequest extends HttpMessage
{
    /** @var QueryMapType|null  */
    private ?array $queryMap = null;

    /** @var QueryPairsType|null */
    private ?array $queryPairs = null;

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        private string $method,
        private PsrUri $uri,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @param non-empty-string $method
     */
    protected function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function getUri(): PsrUri
    {
        return $this->uri;
    }

    protected function setUri(PsrUri $uri): void
    {
        if ($this->uri->getQuery() !== $uri->getQuery()) {
            $this->queryMap = null;
            $this->queryPairs = null;
        }

        $this->uri = $uri;
    }

    /**
     * @link https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
     */
    public function isIdempotent(): bool
    {
        return \in_array($this->getMethod(), ['GET', 'HEAD', 'PUT', 'DELETE'], true);
    }

    public function hasQueryParameter(string $key): bool
    {
        return isset($this->getQueryParameters()[$key]);
    }

    public function getQueryParameter(string $key): ?string
    {
        return $this->getQueryParameterArray($key)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getQueryParameterArray(string $key): array
    {
        return $this->getQueryParameters()[$key] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function getQueryParameters(): array
    {
        static $mapper;

        $mapper ??= static function (array $values): array {
            \assert(\array_is_list($values));
            return \array_map('strval', $values);
        };

        return \array_map($mapper, $this->getInternalQueryParameters());
    }

    /**
     * @return QueryPairsType
     * @psalm-suppress PropertyTypeCoercion
     */
    public function getQueryParameterPairs(): array
    {
        /** @var QueryPairsType */
        return $this->queryPairs ??= match ($queryString = $this->uri->getQuery()) {
            '' => [],
            default => QueryString::parse($queryString, '&', \PHP_QUERY_RFC1738),
        };
    }

    /**
     * @param QueryValueType $value
     */
    protected function setQueryParameter(string $key, array|string|null $value): void
    {
        $query = $this->getInternalQueryParameters();
        $query[$key] = self::castQueryArrayValues(\is_array($value) ? $value : [$value]);
        $this->updateUriWithQuery($query);
    }

    /**
     * @param QueryValueType $value
     */
    protected function addQueryParameter(string $key, array|string|null $value): void
    {
        $query = $this->getInternalQueryParameters();
        $query[$key] = [
            ...($query[$key] ?? []),
            ...self::castQueryArrayValues(\is_array($value) ? $value : [$value]),
        ];
        $this->updateUriWithQuery($query);
    }

    /**
     * @param QueryArrayType $parameters
     */
    protected function setQueryParameters(array $parameters): void
    {
        $query = self::buildQueryFromParameters($parameters);
        $this->updateUriWithQuery($query);
    }

    /**
     * @param QueryArrayType $parameters
     */
    protected function replaceQueryParameters(array $parameters): void
    {
        $this->updateUriWithQuery([
            ...$this->getInternalQueryParameters(),
            ...self::buildQueryFromParameters($parameters),
        ]);
    }

    protected function removeQueryParameter(string $key): void
    {
        $query = $this->getInternalQueryParameters();
        unset($query[$key]);
        $this->updateUriWithQuery($query);
    }

    protected function removeQuery(): void
    {
        $this->uri = $this->uri->withQuery('');
        $this->queryMap = [];
        $this->queryPairs = [];
    }

    /**
     * @return QueryMapType
     */
    private function getInternalQueryParameters(): array
    {
        return $this->queryMap ??= $this->buildQueryFromUri();
    }

    /**
     * @return QueryMapType
     */
    private function buildQueryFromUri(): array
    {
        $query = [];
        foreach ($this->getQueryParameterPairs() as [$key, $value]) {
            $query[$key][] = $value;
        }

        return $query;
    }

    /**
     * @param QueryMapType $query
     */
    private function updateUriWithQuery(array $query): void
    {
        $pairs = [];
        foreach ($query as $key => $values) {
            \array_push($pairs, ...\array_map(static fn ($value) => [$key, $value], $values));
        }

        $this->uri = $this->uri->withQuery(QueryString::build($pairs, '&', \PHP_QUERY_RFC3986) ?? '');
        $this->queryMap = $query;
        $this->queryPairs = $pairs;
    }

    /**
     * @param array<string|int|float|null> $values
     * @return list<string|null>
     */
    private static function castQueryArrayValues(array $values): array
    {
        static $mapper;

        $mapper ??= static fn (mixed $value) => match (true) {
            \is_string($value) => $value,
            \is_null($value) => $value, // string and null check on separate lines for Psalm.
            \is_int($value), \is_float($value), $value instanceof \Stringable => (string) $value,
            default => throw new \TypeError(\sprintf(
                'Query array may contain only types which may be cast to a string; got "%s"',
                \get_debug_type($value),
            )),
        };

        return \array_map($mapper, \array_values($values));
    }

    private static function buildQueryFromParameters(array $parameters): array
    {
        $query = [];
        foreach ($parameters as $key => $values) {
            $query[$key] = self::castQueryArrayValues(\is_array($values) ? $values : [$values]);
        }

        return $query;
    }
}
