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

namespace League\Uri;

use JsonSerializable;
use League\Uri\Components\DataPath;
use League\Uri\Components\Domain;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Components\Host;
use League\Uri\Components\Path;
use League\Uri\Components\Query;
use League\Uri\Contracts\PathInterface;
use League\Uri\Contracts\UriAccess;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Idna\Converter as IdnConverter;
use League\Uri\IPv4\Converter as IPv4Converter;
use League\Uri\KeyValuePair\Converter as KeyValuePairConverter;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function get_object_vars;
use function ltrim;
use function rtrim;
use function str_ends_with;
use function str_starts_with;

class Modifier implements Stringable, JsonSerializable, UriAccess
{
    final public function __construct(protected readonly Psr7UriInterface|UriInterface $uri)
    {
    }

    public static function from(Stringable|string $uri, ?UriFactoryInterface $uriFactory = null): static
    {
        return new static(match (true) {
            $uri instanceof UriAccess => $uri->getUri(),
            $uri instanceof Psr7UriInterface, $uri instanceof UriInterface => $uri,
            $uriFactory instanceof UriFactoryInterface => $uriFactory->createUri((string) $uri),
            default => Uri::new($uri),
        });
    }

    public function getUri(): Psr7UriInterface|UriInterface
    {
        return $this->uri;
    }

    public function getUriString(): string
    {
        return $this->uri->__toString();
    }

    public function jsonSerialize(): string
    {
        return $this->uri->__toString();
    }

    public function __toString(): string
    {
        return $this->uri->__toString();
    }

    /*********************************
     * Query modifier methods
     *********************************/

    /**
     * Change the encoding of the query.
     *
     */
    public function encodeQuery(KeyValuePairConverter|int $to, KeyValuePairConverter|int|null $from = null): static
    {
        $to = match (true) {
            !$to instanceof KeyValuePairConverter => KeyValuePairConverter::fromEncodingType($to),
            default => $to,
        };

        $from = match (true) {
            null === $from => KeyValuePairConverter::fromRFC3986(),
            !$from instanceof KeyValuePairConverter => KeyValuePairConverter::fromEncodingType($from),
            default => $from,
        };

        if ($to == $from) {
            return $this;
        }

        $originalQuery = $this->uri->getQuery();
        $query = QueryString::buildFromPairs(QueryString::parseFromValue($originalQuery, $from), $to);

        return match (true) {
            null === $query,
            '' === $query,
            $originalQuery === $query => $this,
            default => new static($this->uri->withQuery($query)),
        };
    }

    /**
     * Sort the URI query by keys.
     */
    public function sortQuery(): static
    {
        return new static($this->uri->withQuery(
            static::normalizeComponent(
                Query::fromUri($this->uri)->sort()->value(),
                $this->uri
            )
        ));
    }

    /**
     * Add the new query data to the existing URI query.
     */
    public function appendQuery(Stringable|string|null $query): static
    {
        return new static($this->uri->withQuery(
            static::normalizeComponent(
                Query::fromUri($this->uri)->append($query)->value(),
                $this->uri
            )
        ));
    }

    /**
     * Merge query paris with the existing URI query.
     *
     * @param iterable<int, array{0:string, 1:string|null}> $pairs
     */
    public function appendQueryPairs(iterable $pairs): self
    {
        return $this->appendQuery(Query::fromPairs($pairs)->value());
    }

    /**
     * Append PHP query parameters to the existing URI query.
     */
    public function appendQueryParameters(object|array $parameters): self
    {
        return $this->appendQuery(Query::fromVariable($parameters)->value());
    }

    /**
     * Merge a new query with the existing URI query.
     */
    public function mergeQuery(Stringable|string|null $query): static
    {
        return new static($this->uri->withQuery(
            static::normalizeComponent(
                Query::fromUri($this->uri)->merge($query)->value(),
                $this->uri
            )
        ));
    }

    /**
     * Merge query paris with the existing URI query.
     *
     * @param iterable<int, array{0:string, 1:string|null}> $pairs
     */
    public function mergeQueryPairs(iterable $pairs): self
    {
        $currentPairs = [...Query::fromUri($this->uri)->pairs()];
        $pairs = [...$pairs];

        return match (true) {
            [] === $pairs,
            $currentPairs === $pairs => $this,
            default => $this->mergeQuery(Query::fromPairs($pairs)->value()),
        };
    }

    /**
     *  Merge PHP query parameters with the existing URI query.
     */
    public function mergeQueryParameters(object|array $parameters): self
    {
        $parameters = match (true) {
            is_object($parameters) => get_object_vars($parameters),
            default => $parameters,
        };

        $currentParameters = Query::fromUri($this->uri)->parameters();

        return match (true) {
            [] === $parameters,
            $currentParameters === $parameters => $this,
            default => new static($this->uri->withQuery(
                self::normalizeComponent(
                    Query::fromVariable([...$currentParameters, ...$parameters])->value(),
                    $this->uri
                )
            )),
        };
    }

    /**
     * Remove query data according to their key name.
     */
    public function removeQueryPairsByKey(string ...$keys): static
    {
        $query = Query::fromUri($this->uri);
        $newQuery = $query->withoutPairByKey(...$keys)->value();

        return match ($query->value()) {
            $newQuery => $this,
            default => new static($this->uri->withQuery(static::normalizeComponent($newQuery, $this->uri))),
        };
    }

    /**
     * Remove query pair according to their value.
     */
    public function removeQueryPairsByValue(Stringable|string|int|float|bool|null ...$values): static
    {
        $query = Query::fromUri($this->uri);
        $newQuery = $query->withoutPairByValue(...$values)->value();

        return match ($query->value()) {
            $newQuery => $this,
            default => new static($this->uri->withQuery(static::normalizeComponent($newQuery, $this->uri))),
        };
    }

    /**
     * Remove query pair according to their key/value name.
     */
    public function removeQueryPairsByKeyValue(string $key, Stringable|string|int|bool|null $value): static
    {
        $query = Query::fromUri($this->uri);
        $newQuery = $query->withoutPairByKeyValue($key, $value)->value();

        return match ($newQuery) {
            $query->value() => $this,
            default => new static($this->uri->withQuery(static::normalizeComponent($newQuery, $this->uri))),
        };
    }

    /**
     * Remove query data according to their PHP parameter key name.
     */
    public function removeQueryParameters(string ...$keys): static
    {
        $query = Query::fromUri($this->uri);
        $newQuery = $query->withoutParameters(...$keys)->value();

        return match ($newQuery) {
            $query->value() => $this,
            default => new static($this->uri->withQuery(static::normalizeComponent($newQuery, $this->uri))),
        };
    }

    /**
     * Remove empty pairs from the URL query component.
     *
     * A pair is considered empty if it's name is the empty string
     * and its value is either the empty string or the null value
     */
    public function removeEmptyQueryPairs(): static
    {
        return new static($this->uri->withQuery(
            static::normalizeComponent(
                Query::fromUri($this->uri)->withoutEmptyPairs()->value(),
                $this->uri
            )
        ));
    }

    /**
     * Returns an instance where numeric indices associated to PHP's array like key are removed.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the query component normalized so that numeric indexes
     * are removed from the pair key value.
     *
     * ie.: toto[3]=bar[3]&foo=bar becomes toto[]=bar[3]&foo=bar
     */
    public function removeQueryParameterIndices(): static
    {
        $query = Query::fromUri($this->uri);
        $newQuery = $query->withoutNumericIndices()->value();

        return match ($newQuery) {
            $query->value() => $this,
            default => new static($this->uri->withQuery(static::normalizeComponent($newQuery, $this->uri))),
        };
    }

    /*********************************
     * Host modifier methods
     *********************************/

    /**
     * Add the root label to the URI.
     */
    public function addRootLabel(): static
    {
        $host = $this->uri->getHost();

        return match (true) {
            null === $host,
            str_ends_with($host, '.') => $this,
            default => new static($this->uri->withHost($host.'.')),
        };
    }

    /**
     * Append a label or a host to the current URI host.
     *
     * @throws SyntaxError If the host cannot be appended
     */
    public function appendLabel(Stringable|string|null $label): static
    {
        $host = Host::fromUri($this->uri);
        $label = Host::new($label);

        return match (true) {
            null === $label->value() => $this,
            $host->isDomain() => new static($this->uri->withHost(static::normalizeComponent(Domain::new($host)->append($label)->toUnicode(), $this->uri))),
            $host->isIpv4() => new static($this->uri->withHost($host->value().'.'.ltrim($label->value(), '.'))),
            default => throw new SyntaxError('The URI host '.$host->toString().' cannot be appended.'),
        };
    }

    /**
     * Convert the URI host part to its ascii value.
     */
    public function hostToAscii(): static
    {
        $currentHost = $this->uri->getHost();
        $host = IdnConverter::toAsciiOrFail((string) $currentHost);

        return match (true) {
            null === $currentHost,
            '' === $currentHost,
            $host === $currentHost => $this,
            default => new static($this->uri->withHost($host)),
        };
    }

    /**
     * Convert the URI host part to its unicode value.
     */
    public function hostToUnicode(): static
    {
        $currentHost = $this->uri->getHost();
        $host = IdnConverter::toUnicode((string) $currentHost)->domain();

        return match (true) {
            null === $currentHost,
            '' === $currentHost,
            $host === $currentHost => $this,
            default => new static($this->uri->withHost($host)),
        };
    }

    /**
     * Normalizes the URI host content to a IPv4 dot-decimal notation if possible
     * otherwise returns the uri instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function hostToDecimal(): static
    {
        $currentHost = $this->uri->getHost();
        $hostIp = self::ipv4Converter()->toDecimal($currentHost);

        return match (true) {
            null === $currentHost,
            '' === $currentHost,
            null === $hostIp,
            $currentHost === $hostIp => $this,
            default => new static($this->uri->withHost($hostIp)),
        };
    }

    /**
     * Normalizes the URI host content to a IPv4 octal notation if possible
     * otherwise returns the uri instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function hostToOctal(): static
    {
        $currentHost = $this->uri->getHost();
        $hostIp = self::ipv4Converter()->toOctal($currentHost);

        return match (true) {
            null === $currentHost,
            '' === $currentHost,
            null === $hostIp,
            $currentHost === $hostIp  => $this,
            default => new static($this->uri->withHost($hostIp)),
        };
    }

    /**
     * Normalizes the URI host content to a IPv4 octal notation if possible
     * otherwise returns the uri instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function hostToHexadecimal(): static
    {
        $currentHost = $this->uri->getHost();
        $hostIp = self::ipv4Converter()->toHexadecimal($currentHost);

        return match (true) {
            null === $currentHost,
            '' === $currentHost,
            null === $hostIp,
            $currentHost === $hostIp  => $this,
            default => new static($this->uri->withHost($hostIp)),
        };
    }

    /**
     * Prepend a label or a host to the current URI host.
     *
     * @throws SyntaxError If the host cannot be prepended
     */
    public function prependLabel(Stringable|string|null $label): static
    {
        $host = Host::fromUri($this->uri);
        $label = Host::new($label);

        return match (true) {
            null === $label->value() => $this,
            $host->isIpv4() => new static($this->uri->withHost(rtrim($label->value(), '.').'.'.$host->value())),
            $host->isDomain() => new static($this->uri->withHost(static::normalizeComponent(Domain::new($host)->prepend($label)->toUnicode(), $this->uri))),
            default => throw new SyntaxError('The URI host '.$host->toString().' cannot be prepended.'),
        };
    }

    /**
     * Remove host labels according to their offset.
     */
    public function removeLabels(int ...$keys): static
    {
        return new static($this->uri->withHost(
            static::normalizeComponent(
                Domain::fromUri($this->uri)->withoutLabel(...$keys)->toUnicode(),
                $this->uri
            )
        ));
    }

    /**
     * Remove the root label to the URI.
     */
    public function removeRootLabel(): static
    {
        $host = $this->uri->getHost();

        return match (true) {
            null === $host,
            '' === $host,
            !str_ends_with($host, '.') => $this,
            default => new static($this->uri->withHost(substr($host, 0, -1))),
        };
    }

    /**
     * Slice the host from the URI.
     */
    public function sliceLabels(int $offset, ?int $length = null): static
    {
        $currentHost = $this->uri->getHost();
        $host = Domain::new($currentHost)->slice($offset, $length);

        return match (true) {
            $host->value() === $currentHost,
            $host->toUnicode() === $currentHost => $this,
            default => new static($this->uri->withHost($host->toUnicode())),
        };
    }

    /**
     * Remove the host zone identifier.
     */
    public function removeZoneId(): static
    {
        $host = Host::fromUri($this->uri);

        return match (true) {
            $host->hasZoneIdentifier() => new static($this->uri->withHost(
                static::normalizeComponent(
                    Host::fromUri($this->uri)->withoutZoneIdentifier()->value(),
                    $this->uri
                )
            )),
            default => $this,
        };
    }

    /**
     * Replace a label of the current URI host.
     */
    public function replaceLabel(int $offset, Stringable|string|null $label): static
    {
        return new static($this->uri->withHost(
            static::normalizeComponent(
                Domain::fromUri($this->uri)->withLabel($offset, $label)->toUnicode(),
                $this->uri
            )
        ));
    }

    /*********************************
     * Path modifier methods
     *********************************/

    /**
     * Add a new base path to the URI path.
     */
    public function addBasePath(Stringable|string $path): static
    {
        /** @var HierarchicalPath $path */
        $path = HierarchicalPath::new($path)->withLeadingSlash();
        /** @var HierarchicalPath $currentPath */
        $currentPath = HierarchicalPath::fromUri($this->uri)->withLeadingSlash();

        return new static(match (true) {
            !str_starts_with($currentPath->toString(), $path->toString()) => $this->uri->withPath($path->append($currentPath)->toString()),
            default => static::normalizePath($this->uri, $currentPath),
        });
    }

    /**
     * Add a leading slash to the URI path.
     */
    public function addLeadingSlash(): static
    {
        $path = $this->uri->getPath();

        return match (true) {
            str_starts_with($path, '/') => $this,
            default => new static($this->uri->withPath('/'.$path)),
        };
    }

    /**
     * Add a trailing slash to the URI path.
     */
    public function addTrailingSlash(): static
    {
        $path = $this->uri->getPath();

        return match (true) {
            str_ends_with($path, '/') => $this,
            default => new static($this->uri->withPath($path.'/')),
        };
    }

    /**
     * Append a new segment or a new path to the URI path.
     */
    public function appendSegment(Stringable|string $segment): static
    {
        return new static(static::normalizePath($this->uri, HierarchicalPath::fromUri($this->uri)->append($segment)));
    }

    /**
     * Convert the Data URI path to its ascii form.
     */
    public function dataPathToAscii(): static
    {
        return new static($this->uri->withPath(DataPath::fromUri($this->uri)->toAscii()->toString()));
    }

    /**
     * Convert the Data URI path to its binary (base64encoded) form.
     */
    public function dataPathToBinary(): static
    {
        return new static($this->uri->withPath(DataPath::fromUri($this->uri)->toBinary()->toString()));
    }

    /**
     * Prepend a new segment or a new path to the URI path.
     */
    public function prependSegment(Stringable|string $segment): static
    {
        return new static(static::normalizePath($this->uri, HierarchicalPath::fromUri($this->uri)->prepend($segment)));
    }

    /**
     * Remove a base path from the URI path.
     */
    public function removeBasePath(Stringable|string $path): static
    {
        $basePath = HierarchicalPath::new($path)->withLeadingSlash()->toString();
        $currentPath = HierarchicalPath::fromUri($this->uri)->withLeadingSlash()->toString();
        $newPath = substr($currentPath, strlen($basePath));

        return match (true) {
            '/' === $basePath,
            !str_starts_with($currentPath, $basePath),
            !str_starts_with($newPath, '/') => $this,
            default => new static($this->uri->withPath($newPath)),
        };
    }

    /**
     * Remove dot segments from the URI path.
     */
    public function removeDotSegments(): static
    {
        return new static($this->uri->withPath(Path::fromUri($this->uri)->withoutDotSegments()->toString()));
    }

    /**
     * Remove empty segments from the URI path.
     */
    public function removeEmptySegments(): static
    {
        return new static($this->uri->withPath(HierarchicalPath::fromUri($this->uri)->withoutEmptySegments()->toString()));
    }

    /**
     * Remove the leading slash from the URI path.
     */
    public function removeLeadingSlash(): static
    {
        return new static(static::normalizePath($this->uri, Path::fromUri($this->uri)->withoutLeadingSlash()));
    }

    /**
     * Remove the trailing slash from the URI path.
     */
    public function removeTrailingSlash(): static
    {
        $path = $this->uri->getPath();

        return match (true) {
            !str_ends_with($path, '/') => $this,
            default => new static($this->uri->withPath(substr($path, 0, -1))),
        };
    }

    /**
     * Remove path segments from the URI path according to their offsets.
     */
    public function removeSegments(int ...$keys): static
    {
        return new static($this->uri->withPath(HierarchicalPath::fromUri($this->uri)->withoutSegment(...$keys)->toString()));
    }

    /**
     * Replace the URI path basename.
     */
    public function replaceBasename(Stringable|string $basename): static
    {
        return new static(static::normalizePath($this->uri, HierarchicalPath::fromUri($this->uri)->withBasename($basename)));
    }

    /**
     * Replace the data URI path parameters.
     */
    public function replaceDataUriParameters(Stringable|string $parameters): static
    {
        return new static($this->uri->withPath(DataPath::fromUri($this->uri)->withParameters($parameters)->toString()));
    }

    /**
     * Replace the URI path dirname.
     */
    public function replaceDirname(Stringable|string $dirname): static
    {
        return new static(static::normalizePath($this->uri, HierarchicalPath::fromUri($this->uri)->withDirname($dirname)));
    }

    /**
     * Replace the URI path basename extension.
     */
    public function replaceExtension(Stringable|string $extension): static
    {
        return new static($this->uri->withPath(HierarchicalPath::fromUri($this->uri)->withExtension($extension)->toString()));
    }

    /**
     * Replace a segment from the URI path according its offset.
     */
    public function replaceSegment(int $offset, Stringable|string $segment): static
    {
        return new static($this->uri->withPath(HierarchicalPath::fromUri($this->uri)->withSegment($offset, $segment)->toString()));
    }

    /**
     * Slice the host from the URI.
     */
    public function sliceSegments(int $offset, ?int $length = null): static
    {
        return new static(static::normalizePath($this->uri, HierarchicalPath::fromUri($this->uri)->slice($offset, $length)));
    }

    /**
     * Normalize a URI path.
     *
     * Make sure the path always has a leading slash if an authority is present
     * and the path is not the empty string.
     */
    final protected static function normalizePath(Psr7UriInterface|UriInterface $uri, PathInterface $path): Psr7UriInterface|UriInterface
    {
        $pathString = $path->toString();
        $authority = $uri->getAuthority();

        return match (true) {
            '' === $pathString,
            '/' === $pathString[0],
            null === $authority,
            '' === $authority => $uri->withPath($pathString),
            default => $uri->withPath('/'.$pathString),
        };
    }

    /**
     * Normalize the URI component value depending on the subject interface.
     *
     * null value MUST be converted to the empty string if a Psr7 UriInterface is being manipulated.
     */
    final protected static function normalizeComponent(?string $component, Psr7UriInterface|UriInterface $uri): ?string
    {
        return match (true) {
            $uri instanceof Psr7UriInterface => (string) $component,
            default => $component,
        };
    }

    final protected static function ipv4Converter(): IPv4Converter
    {
        static $converter;

        $converter = $converter ?? IPv4Converter::fromEnvironment();

        return $converter;
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.2.0
     * @codeCoverageIgnore
     * @see Modifier::removeQueryParameters()
     *
     * Remove query data according to their key name.
     */
    public function removeParams(string ...$keys): static
    {
        return $this->removeQueryParameters(...$keys);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.2.0
     * @codeCoverageIgnore
     * @see Modifier::removeEmptyQueryPairs()
     *
     * Remove empty pairs from the URL query component.
     *
     * A pair is considered empty if it's name is the empty string
     * and its value is either the empty string or the null value
     */
    public function removeEmptyPairs(): static
    {
        return $this->removeEmptyQueryPairs();
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.2.0
     * @codeCoverageIgnore
     * @see Modifier::removeQueryPairsByKey()
     *
     * Remove query data according to their key name.
     */
    public function removePairs(string ...$keys): static
    {
        return $this->removeQueryPairsByKey(...$keys);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.2.0
     * @codeCoverageIgnore
     * @see Modifier::removeQueryPairsByKey()
     *
     * Remove query data according to their key name.
     */
    public function removeQueryPairs(string ...$keys): static
    {
        return $this->removeQueryPairsByKey(...$keys);
    }
}
