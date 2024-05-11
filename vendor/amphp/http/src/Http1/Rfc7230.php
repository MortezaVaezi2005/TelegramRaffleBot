<?php declare(strict_types=1);

namespace Amp\Http\Http1;

use Amp\Http\HttpMessage;
use Amp\Http\InvalidHeaderException;
use const Amp\Http\Internal\HEADER_LOWERCASE_MAP;

/**
 * @link https://tools.ietf.org/html/rfc7230
 * @link https://tools.ietf.org/html/rfc2616
 * @link https://tools.ietf.org/html/rfc5234
 *
 * @psalm-import-type HeaderPairsType from HttpMessage
 * @psalm-import-type HeaderMapType from HttpMessage
 */
final class Rfc7230
{
    // We make use of possessive modifiers, which gives a slight performance boost
    private const HEADER_REGEX = "(^([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]++):[ \t]*+((?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r\n)m";
    private const HEADER_FOLD_REGEX = "(\r\n[ \t]++)";

    private const HEADER_SPRINTF = [
        0 => "",
        1 => "%s: %s\r\n",
        2 => "%s: %s\r\n%s: %s\r\n",
        3 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        4 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        5 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        6 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        7 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        8 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        9 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
        10 => "%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n%s: %s\r\n",
    ];

    /**
     * Parses headers according to RFC 7230 and 2616.
     *
     * Allows empty header values, as HTTP/1.0 allows that.
     *
     * @return HeaderMapType Associative array mapping header names to arrays of values.
     *
     * @throws InvalidHeaderException If invalid headers have been passed.
     */
    public static function parseHeaders(string $rawHeaders): array
    {
        $matches = self::matchHeaders($rawHeaders);

        $headers = [];

        foreach ($matches as $header) {
            // Unfortunately, we can't avoid the \strtolower() calls due to \array_change_key_case() behavior
            // when equal headers are present with different casing, e.g. 'set-cookie' and 'Set-Cookie'.
            // Accessing matches directly is slightly faster vs. using foreach (... as [...]).
            $headers[HEADER_LOWERCASE_MAP[$header[1]] ?? \strtolower($header[1])][] = $header[2];
        }

        return $headers;
    }

    /**
     * Parses headers according to RFC 7230 and 2616.
     *
     * Allows empty header values, as HTTP/1.0 allows that.
     *
     * @return HeaderPairsType List of [field, value] header pairs.
     *
     * @throws InvalidHeaderException If invalid headers have been passed.
     */
    public static function parseHeaderPairs(string $rawHeaders): array
    {
        $matches = self::matchHeaders($rawHeaders);

        $headers = [];

        foreach ($matches as $match) {
            // We avoid a call to \trim() here due to the regex.
            // Accessing matches directly is slightly faster vs. using foreach (... as [...]).
            $headers[] = [$match[1], $match[2]];
        }

        return $headers;
    }

    /**
     * @psalm-type MatchListType = list<array{non-empty-string, non-empty-string, string}>
     *
     * @return MatchListType
     */
    private static function matchHeaders(string $rawHeaders): array
    {
        // Ensure that the last line also ends with a newline, this is important.
        \assert(\str_ends_with($rawHeaders, "\r\n"), "Argument 1 must end with CRLF: " . \bin2hex($rawHeaders));

        $count = \preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches, \PREG_SET_ORDER);

        // If these aren't the same, then one line didn't match and there's an invalid header.
        if ($count !== \substr_count($rawHeaders, "\n")) {
            // Folding is deprecated, see https://tools.ietf.org/html/rfc7230#section-3.2.4
            if (self::containsFoldedHeaders($rawHeaders)) {
                throw new InvalidHeaderException("Invalid header syntax: Obsolete line folding");
            }

            throw new InvalidHeaderException("Invalid header syntax");
        }

        /** @var MatchListType $matches */
        return $matches;
    }

    /**
     * Folding is deprecated, see https://tools.ietf.org/html/rfc7230#section-3.2.4.
     */
    public static function containsFoldedHeaders(string $rawHeaders): bool
    {
        if (\preg_match(self::HEADER_FOLD_REGEX, $rawHeaders)) {
            return true;
        }

        return false;
    }

    /**
     * Format headers in to their on-the-wire format.
     *
     * Headers are always validated syntactically. This protects against response splitting and header injection
     * attacks.
     *
     * @param HeaderMapType $headers Headers in a format as returned by {@see parseHeaders()}.
     *
     * @return string Formatted headers.
     *
     * @throws InvalidHeaderException If header names or values are invalid.
     */
    public static function formatHeaders(array $headers): string
    {
        $headerList = [];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                // PHP casts integer-like keys to integers
                $headerList[] = [(string) $name, (string) $value];
            }
        }

        return self::formatHeaderPairs($headerList);
    }

    /**
     * Format headers in to their on-the-wire HTTP/1 format.
     *
     * Headers are always validated syntactically. This protects against response splitting and header injection
     * attacks.
     *
     * @param HeaderPairsType $headers List of headers in [field, value] format as returned by
     * {@see HttpMessage::getHeaderPairs()}.
     *
     * @return string Formatted headers.
     *
     * @throws InvalidHeaderException If header names or values are invalid.
     */
    public static function formatHeaderPairs(array $headers): string
    {
        $lines = \count($headers);
        $bytes = \sprintf(
            self::HEADER_SPRINTF[$lines] ?? \str_repeat(self::HEADER_SPRINTF[1], $lines),
            ...\array_merge(...$headers),
        );
        $count = \preg_match_all(self::HEADER_REGEX, $bytes);

        if ($lines !== $count || $lines !== \substr_count($bytes, "\n")) {
            throw new InvalidHeaderException("Invalid headers");
        }

        return $bytes;
    }

    // @codeCoverageIgnoreStart
    private function __construct()
    {
        // forbid instances
    }
    // @codeCoverageIgnoreEnd
}
