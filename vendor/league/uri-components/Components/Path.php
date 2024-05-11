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

use League\Uri\Contracts\PathInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Encoder;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function array_pop;
use function array_reduce;
use function end;
use function explode;
use function implode;
use function substr;

final class Path extends Component implements PathInterface
{
    private const DOT_SEGMENTS = ['.' => 1, '..' => 1];
    private const SEPARATOR = '/';

    private readonly string $path;

    /**
     * New instance.
     */
    private function __construct(Stringable|string $path)
    {
        $this->path = $this->validate($path);
    }

    /**
     * Validate the component content.
     */
    private function validate(Stringable|string $path): string
    {
        /** @var string $path */
        $path = $this->validateComponent($path);

        return $path;
    }

    /**
     * Returns a new instance from a string or a stringable object.
     */
    public static function new(Stringable|string $value = ''): self
    {
        return new self($value);
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        $uri = self::filterUri($uri);
        $path = $uri->getPath();
        $authority = $uri->getAuthority();

        return match (true) {
            null === $authority, '' === $authority, '' === $path, '/' === $path[0] => new self($path),
            default => new self('/'.$path),
        };
    }

    public function value(): ?string
    {
        return Encoder::encodePath($this->path);
    }

    public function decoded(): string
    {
        return $this->path;
    }

    public function isAbsolute(): bool
    {
        return self::SEPARATOR === ($this->path[0] ?? '');
    }

    public function hasTrailingSlash(): bool
    {
        return '' !== $this->path && self::SEPARATOR === substr($this->path, -1);
    }

    public function withoutDotSegments(): PathInterface
    {
        $current = $this->toString();
        if (!str_contains($current, '.')) {
            return $this;
        }

        $input = explode(self::SEPARATOR, $current);
        $new = implode(self::SEPARATOR, array_reduce($input, $this->filterDotSegments(...), []));
        if (isset(self::DOT_SEGMENTS[end($input)])) {
            $new .= self::SEPARATOR ;
        }

        return new self($new);
    }

    /**
     * Filter Dot segment according to RFC3986.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-5.2.4
     *
     * @return string[]
     */
    private function filterDotSegments(array $carry, string $segment): array
    {
        if ('..' === $segment) {
            array_pop($carry);

            return $carry;
        }

        if (!isset(self::DOT_SEGMENTS[$segment])) {
            $carry[] = $segment;
        }

        return $carry;
    }

    /**
     * Returns an instance with a trailing slash.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the path component with a trailing slash
     */
    public function withTrailingSlash(): PathInterface
    {
        return $this->hasTrailingSlash() ? $this : new self($this->toString().self::SEPARATOR);
    }

    public function withoutTrailingSlash(): PathInterface
    {
        return !$this->hasTrailingSlash() ? $this : new self(substr($this->toString(), 0, -1));
    }

    public function withLeadingSlash(): PathInterface
    {
        return $this->isAbsolute() ? $this : new self(self::SEPARATOR.$this->toString());
    }

    public function withoutLeadingSlash(): PathInterface
    {
        return !$this->isAbsolute() ? $this : new self(substr($this->toString(), 1));
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see HierarchicalPath::new()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from a string or a stringable object.
     */
    public static function createFromString(Stringable|string|int $path): self
    {
        return self::new((string) $path);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see HierarchicalPath::fromUri()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from a URI object.
     */
    public static function createFromUri(Psr7UriInterface|UriInterface $uri): self
    {
        return self::fromUri($uri);
    }
}
