<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\Http\Client\HttpException;
use Amp\Http\Cookie\InvalidCookieException;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Cookie\ResponseCookie;
use Psr\Http\Message\UriInterface as PsrUri;

final class LocalCookieJar implements CookieJar
{
    /**
     * Cookies stored by Domain -> Path -> Name.
     * @var array<string, array<string, array<string, ResponseCookie>>>
     */
    private array $cookies = [];

    public function store(ResponseCookie ...$cookies): void
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getDomain() === '') {
                throw new HttpException("Can't store cookie without domain information.");
            }

            $this->cookies[$cookie->getDomain()][$cookie->getPath() ?: '/'][$cookie->getName()] = $cookie;
        }
    }

    public function get(PsrUri $uri): array
    {
        $this->clearExpiredCookies();

        $path = $uri->getPath() ?: '/';
        $domain = $uri->getHost();

        $isRequestSecure = $uri->getScheme() === 'https';

        $matches = [];

        foreach ($this->cookies as $cookieDomain => $domainCookies) {
            if (!$this->matchesDomain($domain, $cookieDomain)) {
                continue;
            }

            foreach ($domainCookies as $cookiePath => $pathCookies) {
                if (!$this->matchesPath($path, $cookiePath)) {
                    continue;
                }

                foreach ($pathCookies as $cookie) {
                    if ($isRequestSecure || !$cookie->isSecure()) {
                        try {
                            $matches[] = new RequestCookie($cookie->getName(), $cookie->getValue());
                        } catch (InvalidCookieException) {
                            // ignore cookie
                        }
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * @return list<ResponseCookie>
     */
    public function getAll(): array
    {
        $cookies = [];

        foreach ($this->cookies as $cookiesPerDomain) {
            foreach ($cookiesPerDomain as $cookiesPerPath) {
                foreach ($cookiesPerPath as $cookie) {
                    $cookies[] = $cookie;
                }
            }
        }

        return $cookies;
    }

    public function clear(): void
    {
        $this->cookies = [];
    }

    private function clearExpiredCookies(): void
    {
        $now = \time();
        foreach ($this->cookies as $domain => $domainCookies) {
            foreach ($domainCookies as $path => $pathCookies) {
                foreach ($pathCookies as $name => $cookie) {
                    if (($cookie->getExpiry()?->getTimestamp() ?? $now) < $now) {
                        unset($this->cookies[$domain][$path][$name]);
                    }
                }
            }
        }
    }

    /**
     * @link http://tools.ietf.org/html/rfc6265#section-5.1.3
     */
    private function matchesDomain(string $requestDomain, string $cookieDomain): bool
    {
        if ($requestDomain === \ltrim($cookieDomain, '.')) {
            return true;
        }

        /** @noinspection SubStrUsedAsStrPosInspection */
        $isWildcardCookieDomain = $cookieDomain[0] === '.';
        if (!$isWildcardCookieDomain) {
            return false;
        }

        if (\filter_var($requestDomain, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (\substr($requestDomain, 0, -\strlen($cookieDomain)) . $cookieDomain === $requestDomain) {
            return true;
        }

        return false;
    }

    /**
     * @link http://tools.ietf.org/html/rfc6265#section-5.1.4
     */
    private function matchesPath(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }

        if (!\str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        if ((\str_ends_with($cookiePath, '/') || $requestPath[\strlen($cookiePath)] === '/')) {
            return true;
        }

        return false;
    }
}
