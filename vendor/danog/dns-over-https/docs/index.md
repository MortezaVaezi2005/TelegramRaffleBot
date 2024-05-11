---
title: Asynchronous secure DNS-over-HTTPS Resolution
permalink: /
---
`danog/dns-over-https` provides asynchronous DNS name resolution for [Amp](http://amphp.org/amp).

## Installation

```bash
composer require danog/dns-over-https
```

## Usage

`danog/dns-over-https` provides asynchronous and secure DNS-over-HTTPS name resolution for [Amp](https://github.com/amphp/amp).  
Supports [RFC 8484](https://tools.ietf.org/html/rfc8484) POST and GET syntaxes as well as [Google's proprietary JSON DNS format](https://developers.google.com/speed/public-dns/docs/dns-over-https).  
Supports passing custom headers for [domain fronting](https://en.wikipedia.org/wiki/Domain_fronting) with google DNS.  

### Configuration

`danog/dns-over-https` requires you provide a `DoHConfig` object to the resolver.  
`DoHConfig` requires an (array of) `DoHNameserver` objects, with a list of `DNS-over-HTTPS` servers to use:  

```php
use Amp\DoH;
use Amp\Dns;

$nameservers = [];

// Defaults to DoH\DoHNameserverType::RFC8484_POST
$nameservers []= new DoH\DoHNameserver('https://mozilla.cloudflare-dns.com/dns-query'); 

$nameservers []= new DoH\DoHNameserver('https://mozilla.cloudflare-dns.com/dns-query', DoH\DoHNameserverType::RFC8484_POST);
$nameservers []= new DoH\DoHNameserver('https://mozilla.cloudflare-dns.com/dns-query', DoH\DoHNameserverType::RFC8484_GET);
$nameservers []= new DoH\DoHNameserver('https://mozilla.cloudflare-dns.com/dns-query', DoH\DoHNameserverType::GOOGLE_JSON);
$nameservers []= new DoH\DoHNameserver('https://dns.google.com/resolve', DoH\DoHNameserverType::GOOGLE_JSON);
$nameservers []= new DoH\DoHNameserver('https://google.com/resolve', DoH\DoHNameserverType::GOOGLE_JSON, ['Host' => 'https://dns.google.com']);

$DohConfig = new DoH\DoHConfig($nameservers);

// Set default resolver for all AMPHP apps to DNS-over-HTTPS resolver
Dns\dnsResolver(new DoH\Rfc8484StubDoHResolver($DohConfig));
```

In the last example, [domain fronting](https://en.wikipedia.org/wiki/Domain_fronting), useful to bypass censorship in non-free countries: from the outside, it looks like the DoH client is connecting to `https://google.com`, but by sending a custom Host HTTP header to the server after the TLS handshake is finished, the server that actually replies is `https://dns.google.com` (this is only possible if both servers are behind a common CDN that allows domain fronting, like google's CDN).  
In normal conditions, it is recommended that you use mozilla+cloudflare's DoH endpoint (`https://mozilla.cloudflare-dns.com/dns-query`), for greater privacy.  

Other parameters that can be passed to the DoHConfig constructor are:  
```php
public function __construct(array $nameservers, \Amp\Artax\Client $artax = null, \Amp\Dns\dnsResolver $resolver = null, \Amp\Dns\ConfigLoader $configLoader = null, \Amp\Cache\Cache $cache = null);
```

You can provide a custom HTTP client to use for resolution, or use a custom subresolver (the subresolver is used to make the first and only plaintext DNS request to obtain the address of the DoH nameserver), or use a [custom configuration](https://amphp.org/dns/#configuration) for the DoH client (and the subresolver, too, if the configuration is provided but the resolver isn't).  
The last parameter can be a custom async caching object.  

### Address Resolution

To resolve addresses using `dns-over-https` first set the global DNS resolver as explained in the [configuration section](#configuration), or use an instance of `Rfc8484StubDoHResolver` instead of `Rfc1035StubResolver`.  

`Amp\Dns\resolve` provides hostname to IP address resolution. It returns an array of IPv4 and IPv6 addresses by default. The type of IP addresses returned can be restricted by passing a second argument with the respective type.

```php
// Example without type restriction. Will return IPv4 and / or IPv6 addresses.
// What's returned depends on what's available for the given hostname.

/** @var Amp\Dns\Record[] $records */
$records = Amp\Dns\resolve("github.com");
```

```php
// Example with type restriction. Will throw an exception if there are no A records.

/** @var Amp\Dns\Record[] $records */
$records = Amp\Dns\resolve("github.com", Amp\Dns\Record::A);
```

### Custom Queries

To resolve addresses using `dns-over-https` first set the global DNS resolver as explained in the [configuration section](#configuration), or use an instance of `Rfc8484StubDoHResolver` instead of `Rfc1035StubResolver`.  

`Amp\Dns\query` supports the various other DNS record types such as `MX`, `PTR`, or `TXT`. It automatically rewrites passed IP addresses for `PTR` lookups.
 
```php
/** @var Amp\Dns\Record[] $records */
$records = Amp\Dns\query("google.com", Amp\Dns\Record::MX);
```

```php
/** @var Amp\Dns\Record[] $records */
$records = Amp\Dns\query("8.8.8.8", Amp\Dns\Record::PTR);
```

### Caching

The `Rfc8484StubDoHResolver` caches responses by default in an `Amp\Cache\LocalCache`. You can set any other `Amp\Cache\Cache` implementation by creating a custom instance of `Rfc8484StubDoHResolver` and setting that via `Amp\Dns\dnsResolver()`, but it's usually unnecessary. If you have a lot of very short running scripts, you might want to consider using a local DNS resolver with a cache instead of setting a custom cache implementation, such as `dnsmasq`. 

### Reloading Configuration

The subresolver (which is the resolver set in the `DoHConfig`, `Rfc1035StubResolver` by default) will cache the configuration of `/etc/resolv.conf` / the Windows Registry and the read host files by default. If you wish to reload them, you can set a periodic timer that requests a background reload of the configuration.

```php
Loop::repeat(60000, function () use ($resolver) {
    Amp\Dns\dnsResolver()->reloadConfig();
});
```

