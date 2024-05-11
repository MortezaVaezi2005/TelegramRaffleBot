<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\WindowsRegistry\KeyNotFoundException;
use Amp\WindowsRegistry\WindowsRegistry;

final class WindowsDnsConfigLoader implements DnsConfigLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    private const NETWORK_CARDS_KEY =
        'HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\NetworkCards';
    private const TCPIP_PARAMETERS_KEY =
        'HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\Interfaces';

    public function __construct(
        private readonly HostLoader $hostLoader = new HostLoader(),
    ) {
    }

    public function loadConfig(): DnsConfig
    {
        $keys = [
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\NameServer",
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\DhcpNameServer",
        ];

        $nameserver = "";

        while ($nameserver === "" && ($key = \array_shift($keys))) {
            try {
                $nameserver = WindowsRegistry::read($key);
            } catch (KeyNotFoundException) {
                // retry other possible locations
            }
        }

        if ($nameserver === "") {
            foreach (self::findNetworkCardGuids() as $guid) {
                foreach (["NameServer", "DhcpNameServer"] as $property) {
                    try {
                        $nameserver = WindowsRegistry::read(self::TCPIP_PARAMETERS_KEY . "\\$guid\\$property");

                        if ($nameserver !== "") {
                            break 2;
                        }
                    } catch (KeyNotFoundException) {
                        // retry other possible locations
                    }
                }
            }
        }

        if ($nameserver === "") {
            throw new DnsConfigException("Could not find a nameserver in the Windows Registry");
        }

        $nameservers = [];

        // Comma is the delimiter for the NameServer key, but space is used for the DhcpNameServer key.
        foreach (\explode(" ", \strtr($nameserver, ",", " ")) as $nameserver) {
            $nameserver = \trim($nameserver);
            $ip = \inet_pton($nameserver);

            if ($ip === false) {
                continue;
            }

            if (isset($ip[15])) { // IPv6
                $nameservers[] = "[" . $nameserver . "]:53";
            } else { // IPv4
                $nameservers[] = $nameserver . ":53";
            }
        }

        $hosts = $this->hostLoader->loadHosts();

        return new DnsConfig($nameservers, $hosts);
    }

    private static function findNetworkCardGuids(): array
    {
        return \array_map(
            static fn (string $key): string => WindowsRegistry::read("$key\\ServiceName"),
            WindowsRegistry::listKeys(self::NETWORK_CARDS_KEY),
        );
    }
}
