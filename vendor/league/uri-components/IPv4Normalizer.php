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

use League\Uri\Components\Host;
use League\Uri\Contracts\AuthorityInterface;
use League\Uri\Contracts\HostInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\MissingFeature;
use League\Uri\IPv4\Calculator;
use League\Uri\IPv4\Converter;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

/**
 * DEPRECATION WARNING! This class will be removed in the next major point release.
 *
 * @deprecated Since version 7.0.0
 * @see Converter
 *
 * @codeCoverageIgnore
 */
final class IPv4Normalizer
{
    private readonly Converter $converter;

    public function __construct(
        Converter|Calculator $calculator
    ) {
        if (!$calculator instanceof Converter) {
            $calculator = new Converter($calculator);
        }

        $this->converter = $calculator;
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Converter::toDecimal()
     *
     * @codeCoverageIgnore
     *
     * Normalizes the host content to a IPv4 dot-decimal notation if possible
     * otherwise returns the Host instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function normalize(Stringable|string|null $host): ?string
    {
        return $this->converter->toDecimal($host);
    }

    /**
     * Returns an instance using a GMP calculator.
     */
    public static function createFromGMP(): self
    {
        return new self(Converter::fromGMP());
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Converter::fromBCMath()
     *
     * @codeCoverageIgnore
     *
     * Returns an instance using a Bcmath calculator.
     */
    public static function createFromBCMath(): self
    {
        return new self(Converter::fromBCMath());
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Converter::fromNative()
     *
     * @codeCoverageIgnore
     *
     * Returns an instance using a PHP native calculator (requires 64bits PHP).
     */
    public static function createFromNative(): self
    {
        return new self(Converter::fromNative());
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @throws MissingFeature If no IPv4Calculator implementing object can be used on the platform
     *
     * @codeCoverageIgnore
     *@see Converter::fromEnvironment()
     *
     * @codeCoverageIgnore
     *
     * Returns an instance using a detected calculator depending on the PHP environment.
     *
     * @deprecated Since version 7.0.0
     */
    public static function createFromServer(): self
    {
        return new self(Converter::fromEnvironment());
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Modifier::hostToDecimal()
     *
     * @codeCoverageIgnore
     *
     * Normalizes the URI host content to a IPv4 dot-decimal notation if possible
     * otherwise returns the uri instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function normalizeUri(UriInterface|Psr7UriInterface $uri): UriInterface|Psr7UriInterface
    {
        $host = $uri->getHost();
        $decimalIPv4 = $this->converter->toDecimal($host);

        return match (true) {
            null === $decimalIPv4,
            $decimalIPv4 === $host => $uri,
            default => $uri->withHost($decimalIPv4),
        };
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Modifier::hostToDecimal()
     *
     * @codeCoverageIgnore
     *
     * Normalizes the authority host content to a IPv4 dot-decimal notation if possible
     * otherwise returns the uri instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function normalizeAuthority(AuthorityInterface $authority): AuthorityInterface
    {
        $host = $authority->getHost();
        $decimalIpv4 = $this->converter->toDecimal($host);

        return match (true) {
            null === $decimalIpv4,
            $decimalIpv4 === $host => $authority,
            default => $authority->withHost($decimalIpv4),
        };
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Modifier::hostToDecimal()
     *
     * @codeCoverageIgnore
     *
     * Normalizes the host content to a IPv4 dot-decimal notation if possible
     * otherwise returns the Host instance unchanged.
     *
     * @see https://url.spec.whatwg.org/#concept-ipv4-parser
     */
    public function normalizeHost(HostInterface $host): HostInterface
    {
        $decimalIPv4 = $this->converter->toDecimal($host->value());

        return match (null) {
            $decimalIPv4 => $host,
            default => Host::new($decimalIPv4),
        };
    }
}
