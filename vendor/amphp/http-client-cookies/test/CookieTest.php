<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\PHPUnit\AsyncTestCase;
use Psr\Http\Message\UriInterface as PsrUri;

abstract class CookieTest extends AsyncTestCase
{
    protected function getUri(string $scheme, string $host, string $path): PsrUri
    {
        $uri = $this->createMock(PsrUri::class);
        $uri->method('getScheme')
            ->willReturn(\strtolower($scheme));
        $uri->method('getHost')
            ->willReturn(\strtolower($host));
        $uri->method('getPath')
            ->willReturn($path);

        return $uri;
    }
}
