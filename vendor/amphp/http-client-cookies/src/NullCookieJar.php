<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\Http\Cookie\ResponseCookie;
use Psr\Http\Message\UriInterface as PsrUri;

final class NullCookieJar implements CookieJar
{
    public function get(PsrUri $uri): array
    {
        return [];
    }

    public function store(ResponseCookie ...$cookies): void
    {
        // nothing to do
    }
}
