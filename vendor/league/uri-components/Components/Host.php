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

use League\Uri\Contracts\AuthorityInterface;
use League\Uri\Contracts\IpHostInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\ConversionFailed;
use League\Uri\Exceptions\MissingFeature;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Idna\Converter as IdnConverter;
use League\Uri\IPv4\Converter as IPv4Converter;
use League\Uri\IPv4Normalizer;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function explode;
use function filter_var;
use function in_array;
use function inet_pton;
use function preg_match;
use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function strpos;
use function strtolower;
use function substr;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

final class Host extends Component implements IpHostInterface
{
    protected const REGEXP_NON_ASCII_PATTERN = '/[^\x20-\x7f]/';

    /**
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * invalid characters in host regular expression
     */
    private const REGEXP_INVALID_HOST_CHARS = '/
        [:\/?#\[\]@ ]  # gen-delims characters as well as the space character
    /ix';

    /**
     * General registered name regular expression.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     * @see https://regex101.com/r/fptU8V/1
     */
    private const REGEXP_REGISTERED_NAME = '/
    (?(DEFINE)
        (?<unreserved>[a-z0-9_~\-])   # . is missing as it is used to separate labels
        (?<sub_delims>[!$&\'()*+,;=])
        (?<encoded>%[A-F0-9]{2})
        (?<reg_name>(?:(?&unreserved)|(?&sub_delims)|(?&encoded))*)
    )
        ^(?:(?&reg_name)\.)*(?&reg_name)\.?$
    /ix';

    /**
     * Domain name regular expression.
     *
     * Everything but the domain name length is validated
     *
     * @see https://tools.ietf.org/html/rfc1034#section-3.5
     * @see https://tools.ietf.org/html/rfc1123#section-2.1
     * @see https://regex101.com/r/71j6rt/1
     */
    private const REGEXP_DOMAIN_NAME = '/
    (?(DEFINE)
        (?<let_dig> [a-z0-9])                         # alpha digit
        (?<let_dig_hyp> [a-z0-9-])                    # alpha digit and hyphen
        (?<ldh_str> (?&let_dig_hyp){0,61}(?&let_dig)) # domain label end
        (?<label> (?&let_dig)((?&ldh_str))?)          # domain label
        (?<domain> (?&label)(\.(?&label)){0,126}\.?)  # domain name
    )
        ^(?&domain)$
    /ix';

    /**
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * IPvFuture regular expression
     */
    private const REGEXP_IP_FUTURE = '/^
        v(?<version>[A-F0-9]+)\.
        (?:
            (?<unreserved>[a-z0-9_~\-\.])|
            (?<sub_delims>[!$&\'()*+,;=:])  # also include the : character
        )+
    $/ix';
    private const REGEXP_GEN_DELIMS = '/[:\/?#\[\]@]/';
    private const ADDRESS_BLOCK = "\xfe\x80";

    private readonly ?string $host;
    private readonly bool $isDomain;
    private readonly ?string $ipVersion;
    private readonly bool $hasZoneIdentifier;

    private function __construct(Stringable|int|string|null $host)
    {
        [
            'host' => $this->host,
            'is_domain' => $this->isDomain,
            'ip_version' => $this->ipVersion,
            'has_zone_identifier' => $this->hasZoneIdentifier,
        ] = $this->parse($host);
    }

    /**
     * @throws ConversionFailed if the submitted IDN host cannot be converted to a valid ascii form
     *
     * @return array{host:string|null, is_domain:bool, ip_version:string|null, has_zone_identifier:bool}
     */
    private function parse(Stringable|int|string|null $host): array
    {
        $host = self::filterComponent($host);

        if (null === $host) {
            return [
                'host' => null,
                'is_domain' => true,
                'ip_version' => null,
                'has_zone_identifier' => false,
            ];
        }

        if ('' === $host) {
            return [
                'host' => '',
                'is_domain' => false,
                'ip_version' => null,
                'has_zone_identifier' => false,
            ];
        }

        static $inMemoryCache = [];
        if (isset($inMemoryCache[$host])) {
            return $inMemoryCache[$host];
        }

        if (100 < count($inMemoryCache)) {
            unset($inMemoryCache[array_key_first($inMemoryCache)]);
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $inMemoryCache[$host] = [
                'host' => $host,
                'is_domain' => false,
                'ip_version' => '4',
                'has_zone_identifier' => false,
            ];
        }

        if ('[' === $host[0] && str_ends_with($host, ']')) {
            $ip_host = substr($host, 1, -1);
            if ($this->isValidIpv6Hostname($ip_host)) {
                return $inMemoryCache[$host] = [
                    'host' => $host,
                    'is_domain' => false,
                    'ip_version' => '6',
                    'has_zone_identifier' => str_contains($ip_host, '%'),
                ];
            }

            if (1 === preg_match(self::REGEXP_IP_FUTURE, $ip_host, $matches) && !in_array($matches['version'], ['4', '6'], true)) {
                return $inMemoryCache[$host] = [
                    'host' => $host,
                    'is_domain' => false,
                    'ip_version' => $matches['version'],
                    'has_zone_identifier' => false,
                ];
            }

            throw new SyntaxError(sprintf('`%s` is an invalid IP literal format.', $host));
        }

        $domainName = rawurldecode($host);
        $isAscii = false;
        if (1 !== preg_match(self::REGEXP_NON_ASCII_PATTERN, $domainName)) {
            $domainName = strtolower($domainName);
            $isAscii = true;
        }

        if (1 === preg_match(self::REGEXP_REGISTERED_NAME, $domainName)) {
            return $inMemoryCache[$domainName] = [
                'host' => $domainName,
                'is_domain' => $this->isValidDomain($domainName),
                'ip_version' => null,
                'has_zone_identifier' => false,
            ];
        }

        if ($isAscii || 1 === preg_match(self::REGEXP_INVALID_HOST_CHARS, $domainName)) {
            throw new SyntaxError(sprintf('`%s` is an invalid domain name : the host contains invalid characters.', $host));
        }

        $host = IdnConverter::toAsciiOrFail($domainName);

        return $inMemoryCache[$host] = [
            'host' => $host,
            'is_domain' => $this->isValidDomain($host),
            'ip_version' => null,
            'has_zone_identifier' => false,
        ];
    }

    /**
     * Tells whether the registered name is a valid domain name according to RFC1123.
     *
     * @see http://man7.org/linux/man-pages/man7/hostname.7.html
     * @see https://tools.ietf.org/html/rfc1123#section-2.1
     */
    private function isValidDomain(string $hostname): bool
    {
        $domainMaxLength = str_ends_with($hostname, '.') ? 254 : 253;

        return !isset($hostname[$domainMaxLength])
            && 1 === preg_match(self::REGEXP_DOMAIN_NAME, $hostname);
    }

    /**
     * Validates an Ipv6 as Host.
     *
     * @see http://tools.ietf.org/html/rfc6874#section-2
     * @see http://tools.ietf.org/html/rfc6874#section-4
     */
    private function isValidIpv6Hostname(string $host): bool
    {
        [$ipv6, $scope] = explode('%', $host, 2) + [1 => null];
        if (null === $scope) {
            return (bool) filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }

        $scope = rawurldecode('%'.$scope);

        return 1 !== preg_match(self::REGEXP_NON_ASCII_PATTERN, $scope)
            && 1 !== preg_match(self::REGEXP_GEN_DELIMS, $scope)
            && false !== filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && str_starts_with((string)inet_pton((string)$ipv6), self::ADDRESS_BLOCK);
    }

    public static function new(Stringable|string|null $value = null): self
    {
        return new self($value);
    }

    /**
     * Returns a host from an IP address.
     *
     * @throws MissingFeature If detecting IPv4 is not possible
     * @throws SyntaxError If the $ip cannot be converted into a Host
     */
    public static function fromIp(Stringable|string $ip, string $version = ''): self
    {
        if ('' !== $version) {
            return new self('[v'.$version.'.'.$ip.']');
        }

        $ip = (string) $ip;
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return new self('['.$ip.']');
        }

        if (str_contains($ip, '%')) {
            [$ipv6, $zoneId] = explode('%', rawurldecode($ip), 2) + [1 => ''];

            return new self('['.$ipv6.'%25'.rawurlencode($zoneId).']');
        }

        $host = IPv4Converter::fromEnvironment()->toDecimal($ip);
        if (null === $host) {
            throw new SyntaxError(sprintf('`%s` is an invalid IP Host.', $ip));
        }

        return new self($host);
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        $uri = self::filterUri($uri);
        $component = $uri->getHost();

        return match (true) {
            $uri instanceof UriInterface, '' !== $component => new self($component),
            default => new self(null),
        };
    }

    /**
     * Create a new instance from an Authority object.
     */
    public static function fromAuthority(Stringable|string $authority): self
    {
        return match (true) {
            $authority instanceof AuthorityInterface => new self($authority->getHost()),
            default => new self(Authority::new($authority)->getHost()),
        };
    }

    public function value(): ?string
    {
        return $this->host;
    }

    public function toAscii(): ?string
    {
        return $this->value();
    }

    public function toUnicode(): ?string
    {
        return match (true) {
            null !== $this->ipVersion,
            null === $this->host => $this->host,
            default => IdnConverter::toUnicode($this->host)->domain(),
        };
    }

    public function getIpVersion(): ?string
    {
        return $this->ipVersion;
    }

    public function getIp(): ?string
    {
        if (null === $this->ipVersion) {
            return null;
        }

        if ('4' === $this->ipVersion) {
            return $this->host;
        }

        $ip = substr((string) $this->host, 1, -1);
        if ('6' !== $this->ipVersion) {
            return substr($ip, (int) strpos($ip, '.') + 1);
        }

        $pos = strpos($ip, '%');
        if (false === $pos) {
            return $ip;
        }

        return substr($ip, 0, $pos).'%'.rawurldecode(substr($ip, $pos + 3));
    }

    public function isRegisteredName(): bool
    {
        return !$this->isIp();
    }

    public function isDomain(): bool
    {
        return $this->isDomain;
    }

    public function isIp(): bool
    {
        return null !== $this->ipVersion;
    }

    public function isIpv4(): bool
    {
        return '4' === $this->ipVersion;
    }

    public function isIpv6(): bool
    {
        return '6' === $this->ipVersion;
    }

    public function isIpFuture(): bool
    {
        return !in_array($this->ipVersion, [null, '4', '6'], true);
    }

    public function hasZoneIdentifier(): bool
    {
        return $this->hasZoneIdentifier;
    }

    public function withoutZoneIdentifier(): IpHostInterface
    {
        if (!$this->hasZoneIdentifier) {
            return $this;
        }

        [$ipv6] = explode('%', substr((string) $this->host, 1, -1));

        return self::fromIp($ipv6);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Host::new()
     *
     * @codeCoverageIgnore
     */
    public static function createFromString(Stringable|string|null $host): self
    {
        return self::new($host);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Host::new()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from null.
     */
    public static function createFromNull(): self
    {
        return self::new();
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @throws MissingFeature If detecting IPv4 is not possible
     * @throws SyntaxError If the $ip cannot be converted into a Host
     * @deprecated Since version 7.0.0
     * @see Host::fromIp()
     *
     * @codeCoverageIgnore
     *
     * Returns a host from an IP address.
     *
     */
    public static function createFromIp(string $ip, string $version = '', ?IPv4Normalizer $normalizer = null): self
    {
        return self::fromIp($ip, $version);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Host::fromUri()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from a URI object.
     */
    public static function createFromUri(Psr7UriInterface|UriInterface $uri): self
    {
        return self::fromUri($uri);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Host::fromAuthority()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from an Authority object.
     */
    public static function createFromAuthority(Stringable|string $authority): self
    {
        return self::fromAuthority($authority);
    }
}
