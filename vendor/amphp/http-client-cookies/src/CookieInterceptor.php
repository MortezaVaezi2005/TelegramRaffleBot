<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\Cancellation;
use Amp\Dns\InvalidNameException;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Cookie\Internal\PublicSuffixList;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Cookie\ResponseCookie;

final class CookieInterceptor implements NetworkInterceptor
{
    public function __construct(private readonly CookieJar $cookieJar)
    {
    }

    public function requestViaNetwork(Request $request, Cancellation $cancellation, Stream $stream): Response
    {
        $this->assignApplicableRequestCookies($request);

        $request->interceptPush(function (Request $request, Response $response): Response {
            $this->storeCookies($response);
            return $response;
        });

        $response = $stream->request($request, $cancellation);

        $this->storeCookies($response);

        return $response;
    }

    private function assignApplicableRequestCookies(Request $request): void
    {
        $applicableCookies = $this->cookieJar->get($request->getUri());

        if (!$applicableCookies) {
            return; // No cookies matched our request; we're finished.
        }

        $cookiePairs = [];
        foreach ($applicableCookies as $cookie) {
            $cookiePairs[] = (string) $cookie;
        }

        if ($request->hasHeader('cookie')) {
            \array_unshift($cookiePairs, $request->getHeader('cookie'));
        }

        $request->setHeader('cookie', \implode('; ', $cookiePairs));
    }

    private function createResponseCookie(string $requestDomain, string $rawCookieStr): ?ResponseCookie
    {
        try {
            $cookie = ResponseCookie::fromHeader($rawCookieStr);
            if ($cookie === null) {
                return null;
            }

            if (!$cookie->getDomain()) {
                $cookie = $cookie->withDomain($requestDomain);
            } else {
                // https://tools.ietf.org/html/rfc6265#section-4.1.2.3
                $cookieDomain = $cookie->getDomain();

                // If a domain is set, left dots are ignored and it's always a wildcard
                $cookieDomain = \ltrim($cookieDomain, '.');

                if ($cookieDomain !== $requestDomain) {
                    // ignore cookies on domains that are public suffixes
                    if (PublicSuffixList::isPublicSuffix($cookieDomain)) {
                        return null;
                    }

                    // cookie origin would not be included when sending the cookie
                    $cookieDomainLength = \strlen($cookieDomain);
                    if (\substr($requestDomain, 0, -$cookieDomainLength - 1) . '.' . $cookieDomain !== $requestDomain) {
                        return null;
                    }
                }

                // always add the dot, it's used internally for wildcard matching when an explicit domain is sent
                $cookie = $cookie->withDomain('.' . $cookieDomain);
            }

            return $cookie;
        } catch (InvalidNameException $e) {
            // Ignore malformed Set-Cookie headers
        }

        return null;
    }

    /**
     * @throws HttpException
     */
    private function storeCookies(Response $response): void
    {
        if ($response->hasHeader('set-cookie')) {
            $requestDomain = $response->getRequest()->getUri()->getHost();
            $rawCookies = $response->getHeaderArray('set-cookie');
            $cookies = [];

            foreach ($rawCookies as $rawCookie) {
                $cookie = $this->createResponseCookie($requestDomain, $rawCookie);
                if ($cookie !== null) {
                    $cookies[] = $cookie;
                }
            }

            if ($cookies) {
                $this->cookieJar->store(...$cookies);
            }
        }
    }
}
