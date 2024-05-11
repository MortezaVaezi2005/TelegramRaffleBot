<?php declare(strict_types=1);

namespace Amp\DoH;

use Amp\Dns\DnsConfigException;

final class DoHNameserver
{
    public const RFC8484_GET = DoHNameserverType::RFC8484_GET;
    public const RFC8484_POST = DoHNameserverType::RFC8484_POST;
    public const GOOGLE_JSON = DoHNameserverType::GOOGLE_JSON;

    private readonly string $host;

    public function __construct(
        private readonly string $uri,
        private readonly DoHNameserverType $type = DoHNameserverType::RFC8484_POST,
        private readonly array $headers = []
    ) {
        if (\parse_url($uri, PHP_URL_SCHEME) !== 'https') {
            throw new DnsConfigException('Did not provide a valid HTTPS url!');
        }
        $this->host = \parse_url($uri, PHP_URL_HOST);
    }
    public function getUri(): string
    {
        return $this->uri;
    }
    public function getHost(): string
    {
        return $this->host;
    }
    public function getHeaders(): array
    {
        return $this->headers;
    }
    public function getType(): DoHNameserverType
    {
        return $this->type;
    }
    public function __toString(): string
    {
        return $this->uri;
    }
}
