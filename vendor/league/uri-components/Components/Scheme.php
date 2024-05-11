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

use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function preg_match;
use function sprintf;
use function strtolower;

final class Scheme extends Component
{
    private const REGEXP_SCHEME = ',^[a-z]([-a-z0-9+.]+)?$,i';

    private readonly ?string $scheme;

    private function __construct(Stringable|string|null $scheme)
    {
        $this->scheme = $this->validate($scheme);
    }

    /**
     * Validate a scheme.
     *
     * @throws SyntaxError if the scheme is invalid
     */
    private function validate(Stringable|string|null $scheme): ?string
    {
        $scheme = self::filterComponent($scheme);
        if (null === $scheme) {
            return null;
        }

        $fScheme = strtolower($scheme);

        static $inMemoryCache = [];
        if (isset($inMemoryCache[$fScheme])) {
            return $inMemoryCache[$fScheme];
        }

        if (1 !== preg_match(self::REGEXP_SCHEME, $fScheme)) {
            throw new SyntaxError(sprintf("The scheme '%s' is invalid.", $scheme));
        }

        if (100 < count($inMemoryCache)) {
            unset($inMemoryCache[array_key_first($inMemoryCache)]);
        }

        return $inMemoryCache[$fScheme] = $fScheme;
    }

    public static function new(Stringable|string|null $value = null): self
    {
        return new self($value);
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        $uri = self::filterUri($uri);
        $component = $uri->getScheme();

        return match (true) {
            $uri instanceof UriInterface, '' !== $component => new self($component),
            default => new self(null),
        };
    }

    public function value(): ?string
    {
        return $this->scheme;
    }

    public function getUriComponent(): string
    {
        return $this->value().(null === $this->scheme ? '' : ':');
    }


    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Scheme::new()
     *
     * @codeCoverageIgnore
     */
    public static function createFromString(Stringable|string $scheme): self
    {
        return self::new($scheme);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Scheme::fromUri()
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
