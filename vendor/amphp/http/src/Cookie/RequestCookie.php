<?php declare(strict_types=1);

namespace Amp\Http\Cookie;

/**
 * A cookie as sent in a request's 'cookie' header, so without any attributes.
 *
 * This class does not deal with encoding of arbitrary names and values. If you want to use arbitrary values, please use
 * an encoding mechanism like Base64 or URL encoding.
 *
 * @link https://tools.ietf.org/html/rfc6265#section-5.4
 */
final class RequestCookie implements \Stringable
{
    private const NAME_REGEX = /** @lang RegExp */ '(^[^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]*+$)';
    private const VALUE_REGEX = /** @lang RegExp */ '(^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*+$)';

    /**
     * Parses the cookies from a 'cookie' header.
     *
     * Note: Parsing is aborted if there's an invalid value and no cookies are returned.
     *
     * @param string $string Valid 'cookie' header line.
     *
     * @return list<RequestCookie>
     */
    public static function fromHeader(string $string): array
    {
        $cookies = \explode(";", $string);
        $result = [];

        try {
            foreach ($cookies as $cookie) {
                // Ignore zero-length cookie.
                if (\trim($cookie) === '') {
                    continue;
                }

                $parts = \explode('=', $cookie, 2);

                if (\count($parts) !== 2) {
                    return [];
                }

                [$name, $value] = $parts;

                $name = \trim($name);
                if ($name === '') {
                    return [];
                }

                // We can safely trim quotes, as they're not allowed within cookie values
                $result[] = new self($name, \trim($value, " \t\""));
            }
        } catch (InvalidCookieException) {
            return [];
        }

        return $result;
    }

    /**
     * @param non-empty-string $name Cookie name in its decoded form.
     * @param string $value Cookie value in its decoded form.
     *
     * @throws InvalidCookieException If name or value is invalid.
     */
    public function __construct(private string $name, private string $value = '')
    {
        if (!\preg_match(self::NAME_REGEX, $name)) {
            throw new InvalidCookieException("Invalid cookie name: '{$name}'");
        }

        if (!\preg_match(self::VALUE_REGEX, $value)) {
            throw new InvalidCookieException("Invalid cookie value: '{$value}'");
        }
    }

    /**
     * @return non-empty-string Name of the cookie.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param non-empty-string $name
     */
    public function withName(string $name): self
    {
        if (!\preg_match(self::NAME_REGEX, $name)) {
            throw new InvalidCookieException("Invalid cookie name: '{$name}'");
        }

        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    /**
     * @return string Value of the cookie.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    public function withValue(string $value): self
    {
        if (!\preg_match(self::VALUE_REGEX, $value)) {
            throw new InvalidCookieException("Invalid cookie value: '{$value}'");
        }

        $clone = clone $this;
        $clone->value = $value;

        return $clone;
    }

    /**
     * @return string Representation of the cookie as in a 'cookie' header.
     */
    public function toString(): string
    {
        return $this->name . '=' . $this->value;
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
