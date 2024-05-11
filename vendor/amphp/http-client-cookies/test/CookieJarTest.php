<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\RequestCookie;
use Amp\Http\Cookie\ResponseCookie;

abstract class CookieJarTest extends CookieTest
{
    private CookieJar $jar;

    public function setUp(): void
    {
        parent::setUp();

        $this->jar = $this->createJar();
    }

    /**
     * @dataProvider provideCookieDomainMatchData
     */
    public function testCookieDomainMatching(ResponseCookie $cookie, string $domain, bool $returned): void
    {
        $this->jar->store($cookie);

        $requestCookies = $this->jar->get($this->getUri('https', $domain, '/'));

        if ($returned) {
            $requestCookie = new RequestCookie($cookie->getName(), $cookie->getValue());
            $this->assertSame((string) $requestCookie, \implode('; ', $requestCookies));
        } else {
            $this->assertSame([], $requestCookies);
        }
    }

    public function provideCookieDomainMatchData(): array
    {
        // See http://stackoverflow.com/a/1063760/2373138 for cases
        return [
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('.foo.bar.example.com')),
                'foo.bar',
                false,
            ], /* previous security issue */
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('.example.com')),
                'example.com',
                true,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('.example.com')),
                'www.example.com',
                true,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('example.com')),
                'example.com',
                true,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('example.com')),
                'www.example.com',
                false,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('example.com')),
                'anotherexample.com',
                false,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('anotherexample.com')),
                'example.com',
                false,
            ],
        ];
    }

    abstract protected function createJar(): CookieJar;
}
