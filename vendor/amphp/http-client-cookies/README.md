# amphp/http-client-cookies

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This package provides automatic cookie handling as a plugin for [`amphp/http-client`](https://github.com/amphp/http-client).

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-client-cookies
```

## Usage

`Amp\Http\Client\Cookie\CookieInterceptor` must be registered as a `NetworkInterceptor` to enable automatic cookie handling.
It requires a `CookieJar` implementation, where you can choose between `LocalCookieJar`, `FileCookieJar`, and `NullCookieJar`.

```php
<?php

use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\LocalCookieJar;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

require __DIR__ . '/vendor/autoload.php';

$cookieJar = new LocalCookieJar;

$httpClient = (new HttpClientBuilder)
    ->interceptNetwork(new CookieInterceptor($cookieJar))
    ->build();

$firstResponse = $httpClient->request(new Request('https://google.com/'));
$firstResponse->getBody()->buffer();

$secondResponse = $httpClient->request(new Request('https://google.com/'));
$secondResponse->getBody()->buffer();

$otherDomainResponse = $httpClient->request(new Request('https://amphp.org/'));
$otherDomainResponse->getBody()->buffer();

print "== first response stores cookies ==\r\n";
print \implode("\r\n", $firstResponse->getHeaderArray('set-cookie'));
print "\r\n\r\n";

print "== second request sends cookies again ==\r\n";
print \implode("\r\n", $secondResponse->getRequest()->getHeaderArray('cookie'));
print "\r\n\r\n";

print "== other domain request does not send cookies ==\r\n";
print \implode("\r\n", $otherDomainResponse->getRequest()->getHeaderArray('cookie'));
```

## Examples

More extensive code examples reside in the [`examples`](./examples) directory.

## Versioning

`amphp/http-client-cookies` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
