<?php declare(strict_types=1);

namespace Amp\DoH;

use Amp\Cache\Cache;
use Amp\Cache\LocalCache;
use Amp\Dns\DnsConfigException;
use Amp\Dns\DnsConfigLoader;
use Amp\Dns\DnsResolver;
use Amp\Dns\Rfc1035StubDnsResolver;
use Amp\Dns\UnixDnsConfigLoader;
use Amp\Dns\WindowsDnsConfigLoader;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;

final class DoHConfig
{
    /**
     * @var non-empty-array<DoHNameserver> $nameservers
     */
    private readonly array $nameservers;
    private readonly HttpClient $httpClient;
    private readonly DnsResolver $subResolver;
    private readonly DnsConfigLoader $configLoader;
    private readonly Cache $cache;

    /**
     * @param non-empty-array<DoHNameserver> $nameservers
     */
    public function __construct(array $nameservers, ?HttpClient $httpClient = null, ?DnsResolver $resolver = null, ?DnsConfigLoader $configLoader = null, ?Cache $cache = null)
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if (\count($nameservers) < 1) {
            throw new DnsConfigException("At least one nameserver is required for a valid config");
        }

        foreach ($nameservers as $nameserver) {
            /** @psalm-suppress DocblockContradiction */
            if (!($nameserver instanceof DoHNameserver)) {
                throw new DnsConfigException("Invalid nameserver: {$nameserver}");
            }
        }

        $this->nameservers = $nameservers;
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
        $this->cache = $cache ?? new LocalCache(256, 5.0);
        $this->configLoader = $configLoader ?? (\stripos(PHP_OS, "win") === 0
            ? new WindowsDnsConfigLoader()
            : new UnixDnsConfigLoader());
        $this->subResolver = $resolver ?? new Rfc1035StubDnsResolver(null, $this->configLoader);
    }

    /**
     * @return non-empty-array<DoHNameserver>
     */
    public function getDoHNameservers(): array
    {
        return $this->nameservers;
    }

    public function isDoHNameserver(string $string): bool
    {
        foreach ($this->nameservers as $nameserver) {
            if ($nameserver->getHost() === $string) {
                return true;
            }
        }
        return false;
    }

    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    public function getCache(): Cache
    {
        return $this->cache;
    }
    public function getConfigLoader(): DnsConfigLoader
    {
        return $this->configLoader;
    }
    public function getSubResolver(): DnsResolver
    {
        return $this->subResolver;
    }
}
