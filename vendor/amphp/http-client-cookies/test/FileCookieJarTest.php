<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

class FileCookieJarTest extends CookieJarTest
{
    protected function createJar(): CookieJar
    {
        return new FileCookieJar(\tempnam(\sys_get_temp_dir(), 'amphp-http-client-cookies-test-'));
    }
}
