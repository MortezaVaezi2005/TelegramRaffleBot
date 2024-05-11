<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri\Components;

use ArgumentCountError;
use Closure;
use Countable;
use Iterator;
use IteratorAggregate;
use League\Uri\Contracts\QueryInterface;
use League\Uri\Contracts\UriComponentInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\KeyValuePair\Converter;
use League\Uri\QueryString;
use League\Uri\Uri;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function func_get_arg;
use function func_num_args;
use function get_object_vars;
use function is_array;
use function is_iterable;
use function is_object;
use function is_scalar;
use function iterator_to_array;
use function json_encode;
use function spl_object_hash;
use function str_starts_with;

use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * @see https://url.spec.whatwg.org/#interface-urlsearchparams
 *
 * @implements IteratorAggregate<array{0:string, 1:string}>
 */
final class URLSearchParams implements Countable, IteratorAggregate, UriComponentInterface
{
    private QueryInterface $pairs;

    /**
     * New instance.
     *
     * A string, which will be parsed from application/x-www-form-urlencoded format. A leading '?' character is ignored.
     * A literal sequence of name-value string pairs, or any object with an iterator that produces a sequence of string pairs.
     * A record of string keys and string values. Note that nesting is not supported.
     */
    public function __construct(object|array|string|null $init = '')
    {
        $pairs = self::filterPairs(match (true) {
            $init instanceof self,
            $init instanceof QueryInterface => $init,
            $init instanceof UriComponentInterface => self::parsePairs($init->value()),
            is_iterable($init) => self::formatIterable($init),
            $init instanceof Stringable, !is_object($init) => self::parsePairs(self::formatQuery($init)),
            default => self::yieldPairs($init),
        });

        $this->pairs = Query::fromPairs($pairs);
    }

    /**
     * @return array<int, array{0:string, 1:string|null}>
     */
    private static function parsePairs(string|null $query): array
    {
        return QueryString::parseFromValue($query, Converter::fromFormData());
    }

    /**
     * @return iterable<array{0:string, 1:string}>
     */
    private static function formatIterable(iterable $iterable): iterable
    {
        if (!is_array($iterable)) {
            $iterable = iterator_to_array($iterable);
        }

        return match (true) {
            array_is_list($iterable) => $iterable,
            default => self::yieldPairs($iterable)
        };
    }

    /**
     * Generates an Iterator containing pairs as items from an object or array.
     *
     * If an iterable is given, foreach will loop over the iterable structure
     * If an object is give, foreach will loop over the object public properties if they are defined
     *
     * @param object|iterable<array-key, Stringable|string|float|int|bool|null> $associative
     *
     * @return Iterator<int, array{0:string, 1:string}>
     */
    private static function yieldPairs(object|array $associative): Iterator
    {
        foreach ($associative as $key => $value) { /* @phpstan-ignore-line */
            yield [self::uvString($key), self::uvString($value)];
        }
    }

    /**
     * @return Iterator<int, array{0:string, 1:string}>
     */
    private static function filterPairs(iterable $pairs): iterable
    {
        $filter = static fn ($pair): ?array => match (true) {
            !is_array($pair),
            [0, 1] !== array_keys($pair) => throw new SyntaxError('A pair must be a sequential array starting at `0` and containing two elements.'),
            null !== $pair[1] => [self::uvString($pair[0]), self::uvString($pair[1])],
            '' !== $pair[0] => [self::uvString($pair[0]), ''],
            default => null,
        };

        foreach ($pairs as $pair) {
            if (null !== ($filteredPair = $filter($pair))) {
                yield $filteredPair;
            }
        }
    }

    private static function formatQuery(Stringable|string|null $query): string
    {
        return match (true) {
            null === $query => '',
            str_starts_with((string) $query, '?') => substr((string) $query, 1),
            default => (string) $query,
        };
    }

    /**
     * Normalizes type to UVString.
     *
     * @see https://webidl.spec.whatwg.org/#idl-USVString
     */
    private static function uvString(Stringable|string|float|int|bool|null $value): string
    {
        return match (true) {
            null === $value => 'null',
            false === $value => 'false',
            true === $value => 'true',
            is_float($value) => (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            default => (string) $value,
        };
    }

    /**
     * Returns a new instance from a string or a stringable object.
     *
     * The input will be parsed from application/x-www-form-urlencoded format.
     * The leading '?' character if present is ignored.
     */
    public static function new(Stringable|string|null $query): self
    {
        return new self(Query::fromFormData(self::formatQuery($query)));
    }

    /**
     * Returns a new instance from a literal sequence of name-value string pairs,
     * or any object with an iterator that produces a sequence of string pairs.
     *
     * @param iterable<int, array{0:string, 1:string|null}> $pairs
     */
    public static function fromPairs(iterable $pairs): self
    {
        return new self(Query::fromPairs($pairs));
    }

    /**
     * Returns a new instance from a record of string keys and string values.
     *
     * A record can be, an iterable or any object with scalar or null public properties. Nesting is not supported.
     *
     * @param object|iterable<array-key, Stringable|string|float|int|bool|null> $associative
     */
    public static function fromAssociative(object|array $associative): self
    {
        return new self(Query::fromPairs(self::yieldPairs($associative)));
    }

    /**
     * Returns a new instance from a URI.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        $query = match (true) {
            $uri instanceof Psr7UriInterface,
            $uri instanceof UriInterface => $uri->getQuery(),
            default => Uri::new($uri)->getQuery(),
        };

        return new self(Query::fromPairs(QueryString::parseFromValue($query, Converter::fromFormData())));
    }

    /**
     * Returns a new instance from the input of PHP's http_build_query.
     */
    public static function fromVariable(object|array $parameters): self
    {
        return self::fromPairs(self::parametersToPairs($parameters));
    }

    private static function parametersToPairs(array|object $data, string|int $prefix = '', array &$recursive = []): array
    {
        $yieldParameters = static fn (object|array $data): array => is_array($data) ? $data : get_object_vars($data);

        $pairs = [];
        foreach ($yieldParameters($data) as $name => $value) {
            if (is_object($data)) {
                $id = spl_object_hash($data);
                if (!array_key_exists($id, $recursive)) {
                    $recursive[$id] = 1;
                }
            }

            if (is_object($value)) {
                $id = spl_object_hash($value);
                if (array_key_exists($id, $recursive)) {
                    return [];
                }

                $recursive[$id] = 1;
            }

            if ('' !== $prefix) {
                $name = $prefix.'['.$name.']';
            }

            $pairs = match (true) {
                is_array($value),
                is_object($value) => [...$pairs, ...self::parametersToPairs($value, $name, $recursive)],
                is_scalar($value) => [...$pairs, [$name, self::uvString($value)]],
                default => $pairs,
            };
        }

        return $pairs;
    }

    public function value(): ?string
    {
        return $this->pairs->toFormData();
    }

    /**
     * Returns a query string suitable for use in a URL.
     */
    public function toString(): string
    {
        return $this->value() ?? '';
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function getUriComponent(): string
    {
        $value = $this->value() ?? '';

        return match ('') {
            $value => $value,
            default => '?'.$value,
        };
    }

    /**
     * Returns an iterator allowing iteration through all keys contained in this object.
     *
     * @return iterable<string>
     */
    public function keys(): iterable
    {
        foreach ($this->pairs as [$key, $__]) {
            yield $key;
        }
    }

    /**
     * Returns an iterator allowing iteration through all values contained in this object.
     *
     * @return iterable<string>
     */
    public function values(): iterable
    {
        foreach ($this->pairs as [$__, $value]) {
            yield $value ?? '';
        }
    }

    /**
     * Tells whether the specified parameter is in the search parameters.
     *
     * The method requires at least one parameter as the pair name (string or null)
     * and an optional second and last parameter as the pair value (Stringable|string|float|int|bool|null)
     * <code>
     * $params = new URLSearchParams('a=b&c);
     * $params->has('c');      // return true
     * $params->has('a', 'b'); // return true
     * $params->has('a', 'c'); // return false
     * </code>
     */
    public function has(?string $name): bool
    {
        $name = self::uvString($name);

        return match (func_num_args()) {
            1 => $this->pairs->has($name),
            2 => $this->pairs->hasPair($name, self::uvString(func_get_arg(1))), /* @phpstan-ignore-line */
            default => throw new ArgumentCountError(__METHOD__.' requires at least one argument as the pair name and a second optional argument as the pair value.'),
        };
    }

    /**
     * Returns the first value associated to the given search parameter or null if none exists.
     */
    public function get(?string $name): ?string
    {
        return match (true) {
            $this->has($name) => $this->pairs->get(self::uvString($name)) ?? '',
            default => null,
        };
    }

    /**
     * Returns all the values associated with a given search parameter as an array.
     *
     * @return array<string>
     */
    public function getAll(?string $name): array
    {
        return array_map(
            fn (?string $value): string => $value ?? '',
            $this->pairs->getAll(self::uvString($name))
        );
    }

    /**
     * Tells whether the instance has some parameters.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Tells whether the instance has no parameters.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->size();
    }

    /**
     * Returns the total number of search parameter entries.
     */
    public function size(): int
    {
        return count($this->pairs);
    }

    /**
     * @see URLSearchParams::size()
     */
    public function count(): int
    {
        return $this->size();
    }

    /**
     * Allowing iteration through all key/value pairs contained in this object.
     *
     * The iterator returns key/value pairs in the same order as they appear in the query string.
     * The key and value of each pair are string objects.
     */
    public function entries(): Iterator
    {
        yield from $this->pairs;
    }

    /**
     * @see URLSearchParams::entries()
     */
    public function getIterator(): Iterator
    {
        return $this->entries();
    }

    /**
     * Allows iteration through all values contained in this object via a callback function.
     *
     * @param Closure(string $value, string $key): void $callback
     */
    public function each(Closure $callback): void
    {
        foreach ($this->pairs->pairs() as $key => $value) {
            $callback($value ?? '', $key);
        }
    }

    private function updateQuery(QueryInterface $query): void
    {
        if ($query->value() !== $this->pairs->value()) {
            $this->pairs = $query;
        }
    }

    /**
     * Sets the value associated with a given search parameter to the given value.
     *
     * If there were several matching values, this method deletes the others.
     * If the search parameter doesn't exist, this method creates it.
     */
    public function set(?string $name, Stringable|string|float|int|bool|null $value): void
    {
        $this->updateQuery($this->pairs->withPair(self::uvString($name), self::uvString($value)));
    }

    /**
     * Appends a specified key/value pair as a new search parameter.
     */
    public function append(?string $name, Stringable|string|float|int|bool|null $value): void
    {
        $this->updateQuery($this->pairs->appendTo(self::uvString($name), self::uvString($value)));
    }

    /**
     * Deletes specified parameters and their associated value(s) from the list of all search parameters.
     *
     *  The method requires at least one parameter as the pair name (string or null)
     *  and an optional second and last parameter as the pair value (Stringable|string|float|int|bool|null)
     * <code>
     * $params = new URLSearchParams('a=b&c);
     * $params->delete('c'); //delete all parameters with the key 'c'
     * $params->delete('a', 'b') //delete all pairs with the key 'a' and the value 'b'
     * </code>
     */
    public function delete(?string $name): void
    {
        $name = self::uvString($name);

        $this->updateQuery(match (func_num_args()) {
            1 => $this->pairs->withoutPairByKey($name),
            2 => $this->pairs->withoutPairByKeyValue($name, self::uvString(func_get_arg(1))), /* @phpstan-ignore-line */
            default => throw new ArgumentCountError(__METHOD__.' requires at least one argument as the pair name and a second optional argument as the pair value.'),
        });
    }

    /**
     * Sorts all key/value pairs contained in this object in place and returns undefined.
     *
     * The sort order is according to unicode code points of the keys. This method
     * uses a stable sorting algorithm (i.e. the relative order between
     * key/value pairs with equal keys will be preserved).
     */
    public function sort(): void
    {
        $this->updateQuery($this->pairs->sort());
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.4.0
     * @see URLSearchParams::fromVariable()
     *
     * @codeCoverageIgnore
     *
     */
    public static function fromParameters(object|array $parameters): self
    {
        return new self(Query::fromParameters($parameters));
    }
}
