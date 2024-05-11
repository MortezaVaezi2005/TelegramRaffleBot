<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response as ServerResponse;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Psr\Log\NullLogger;

class ClientCookieTest extends CookieTest
{
    private HttpClient $client;

    private LocalCookieJar $jar;

    private HttpServer $server;

    private string $cookieHeader;

    public function setUp(): void
    {
        parent::setUp();

        $this->jar = new LocalCookieJar;

        $logger = new NullLogger();

        $this->server = SocketHttpServer::createForDirectAccess(
            $logger,
            httpDriverFactory: new DefaultHttpDriverFactory($logger, streamTimeout: 1, connectionTimeout: 1),
        );

        $this->server->expose(new Socket\InternetAddress('127.0.0.1', 0));

        $this->server->start(
            new ClosureRequestHandler(
                fn () => new ServerResponse(HttpStatus::OK, ['set-cookie' => $this->cookieHeader]),
            ),
            new DefaultErrorHandler(),
        );

        $socket = $this->server->getServers()[0] ?? self::fail('No socket servers created by HTTP server');

        $this->client = (new HttpClientBuilder)
            ->usingPool(
                new UnlimitedConnectionPool(
                    new DefaultConnectionFactory(
                        new Socket\StaticSocketConnector($socket->getAddress()->toString(), Socket\socketConnector())
                    ),
                ),
            )
            ->interceptNetwork(new CookieInterceptor($this->jar))
            ->build();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->server->stop();
    }

    /**
     * @dataProvider provideCookieDomainMatchData
     */
    public function testCookieAccepting(ResponseCookie $cookie, string $requestDomain, bool $accept): void
    {
        $this->cookieHeader = (string) $cookie;

        $response = $this->client->request(new Request('http://' . $requestDomain . '/'));
        $response->getBody()->buffer();

        $cookies = $this->jar->getAll();

        if ($accept) {
            $this->assertCount(1, $cookies);
        } else {
            $this->assertSame([], $cookies);
        }

        $this->server->stop();
    }

    public function provideCookieDomainMatchData(): array
    {
        return [
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('.foo.bar.example.com')),
                'foo.bar',
                false,
            ],
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
                true,
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
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('com')),
                'anotherexample.com',
                false,
            ],
            [
                new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('.com')),
                'anotherexample.com',
                false,
            ],
            [new ResponseCookie('foo', 'bar', CookieAttributes::empty()->withDomain('')), 'example.com', true],
        ];
    }
}
