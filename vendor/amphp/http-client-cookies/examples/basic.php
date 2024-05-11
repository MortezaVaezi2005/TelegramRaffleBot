<?php declare(strict_types=1);

use Amp\Http\Client\Cookie\CookieInterceptor;
use Amp\Http\Client\Cookie\FileCookieJar;
use Amp\Http\Client\Cookie\LocalCookieJar;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

require __DIR__ . '/../vendor/autoload.php';

$filename = $argv[1] ?? null;

$cookieJar = $filename
    ? new FileCookieJar(__DIR__ . "/{$filename}.cookies")
    : new LocalCookieJar;

$httpClient = (new HttpClientBuilder)
    ->interceptNetwork(new CookieInterceptor($cookieJar))
    ->build();

$firstResponse = $httpClient->request(new Request('https://google.com/'));

$secondResponse = $httpClient->request(new Request('https://google.com/'));

$otherDomainResponse = $httpClient->request(new Request('https://amphp.org/'));

print "== first request cookies ==\r\n";
print implode("\r\n", $firstResponse->getRequest()->getHeaderArray('cookie'));
print "\r\n\r\n";

print "== first response cookies ==\r\n";
print implode("\r\n", $firstResponse->getHeaderArray('set-cookie'));
print "\r\n\r\n";

print "== second request sends cookies back ==\r\n";
print implode("\r\n", $secondResponse->getRequest()->getHeaderArray('cookie'));
print "\r\n\r\n";

print "== other domain request does not send cookies ==\r\n";
print implode("\r\n", $otherDomainResponse->getRequest()->getHeaderArray('cookie'));
