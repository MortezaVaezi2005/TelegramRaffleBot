<?php declare(strict_types=1);

namespace Amp\Http\Cookie;

/**
 * Cookie attributes as defined in https://tools.ietf.org/html/rfc6265.
 *
 * @link https://tools.ietf.org/html/rfc6265
 */
final class CookieAttributes implements \Stringable
{
    public const SAMESITE_NONE = 'None';
    public const SAMESITE_LAX = 'Lax';
    public const SAMESITE_STRICT = 'Strict';

    /**
     * @return CookieAttributes No cookie attributes.
     *
     * @see self::default()
     */
    public static function empty(): self
    {
        $new = new self;
        $new->httpOnly = false;

        return $new;
    }

    /**
     * @return CookieAttributes Default cookie attributes, which means httpOnly is enabled by default.
     *
     * @see self::empty()
     */
    public static function default(): self
    {
        return new self;
    }

    private string $path = '';

    private string $domain = '';

    private ?int $maxAge = null;

    private ?\DateTimeImmutable $expiry = null;

    private bool $secure = false;

    private bool $httpOnly = true;

    private ?string $sameSite = null;

    private function __construct()
    {
        // only allow creation via named constructors
    }

    /**
     * @param string $path Cookie path.
     *
     * @return self Cloned instance with the specified operation applied. Cloned instance with the specified operation
     *     applied.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.4
     */
    public function withPath(string $path): self
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    /**
     * @param string $domain Cookie domain.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.3
     */
    public function withDomain(string $domain): self
    {
        $new = clone $this;
        $new->domain = $domain;

        return $new;
    }

    /**
     * @param string $sameSite Cookie SameSite attribute value.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function withSameSite(string $sameSite): self
    {
        $normalizedValue = \ucfirst(\strtolower($sameSite));
        if (!\in_array($normalizedValue, [self::SAMESITE_NONE, self::SAMESITE_LAX, self::SAMESITE_STRICT], true)) {
            throw new \Error("Invalid SameSite attribute: " . $sameSite);
        }

        $new = clone $this;
        $new->sameSite = $normalizedValue;

        return $new;
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function withoutSameSite(): self
    {
        $new = clone $this;
        $new->sameSite = null;

        return $new;
    }

    /**
     * Applies the given maximum age to the cookie.
     *
     * @param int $maxAge Cookie maximum age.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutMaxAge()
     * @see self::withExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function withMaxAge(int $maxAge): self
    {
        $new = clone $this;
        $new->maxAge = $maxAge;

        return $new;
    }

    /**
     * Removes any max-age information.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withMaxAge()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function withoutMaxAge(): self
    {
        $new = clone $this;
        $new->maxAge = null;

        return $new;
    }

    /**
     * Applies the given expiry to the cookie.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withMaxAge()
     * @see self::withoutExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.1
     */
    public function withExpiry(\DateTimeInterface $date): self
    {
        $new = clone $this;
        $new->expiry = \DateTimeImmutable::createFromInterface($date);

        return $new;
    }

    /**
     * Removes any expiry information.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.1
     */
    public function withoutExpiry(): self
    {
        $new = clone $this;
        $new->expiry = null;

        return $new;
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutSecure()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function withSecure(): self
    {
        $new = clone $this;
        $new->secure = true;

        return $new;
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withSecure()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function withoutSecure(): self
    {
        $new = clone $this;
        $new->secure = false;

        return $new;
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutHttpOnly()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function withHttpOnly(): self
    {
        $new = clone $this;
        $new->httpOnly = true;

        return $new;
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withHttpOnly()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function withoutHttpOnly(): self
    {
        $new = clone $this;
        $new->httpOnly = false;

        return $new;
    }

    /**
     * @return string Cookie path.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.4
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string Cookie domain.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.3
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @return string Cookie domain.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * @return int|null Cookie maximum age in seconds or `null` if no value is set.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    /**
     * @return \DateTimeImmutable|null Cookie expiry or `null` if no value is set.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function getExpiry(): ?\DateTimeImmutable
    {
        return $this->expiry;
    }

    /**
     * @return bool Whether the secure flag is enabled or not.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * @return bool Whether the httpOnly flag is enabled or not.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    /**
     * @return string Representation of the cookie attributes appended to key=value in a 'set-cookie' header.
     */
    public function toString(): string
    {
        $string = '';

        if ($this->expiry) {
            $string .= '; Expires=' . \gmdate('D, j M Y G:i:s T', $this->expiry->getTimestamp());
        }

        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if ($this->maxAge) {
            $string .= '; Max-Age=' . $this->maxAge;
        }

        if ('' !== $this->path) {
            $string .= '; Path=' . $this->path;
        }

        if ('' !== $this->domain) {
            $string .= '; Domain=' . $this->domain;
        }

        if ($this->secure) {
            $string .= '; Secure';
        }

        if ($this->httpOnly) {
            $string .= '; HttpOnly';
        }

        if ($this->sameSite !== null) {
            $string .= '; SameSite=' . $this->sameSite;
        }

        return $string;
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
