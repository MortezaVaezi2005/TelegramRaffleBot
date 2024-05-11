<?php declare(strict_types=1);

namespace Amp\Websocket\Client;

use Amp\ForbidSerialization;
use Amp\Http\Client\Request;
use Amp\Http\HttpMessage;
use Amp\Http\HttpRequest;
use League\Uri;
use Psr\Http\Message\UriInterface as PsrUri;

/**
 * @psalm-import-type HeaderParamArrayType from HttpMessage
 * @psalm-import-type HeaderParamValueType from HttpMessage
 * @psalm-import-type QueryArrayType from HttpRequest
 * @psalm-import-type QueryValueType from HttpRequest
 */
final class WebsocketHandshake extends HttpRequest
{
    use ForbidSerialization;

    private float $tcpConnectTimeout = 10;

    private float $tlsHandshakeTimeout = 10;

    private int $headerSizeLimit = Request::DEFAULT_HEADER_SIZE_LIMIT;

    /**
     * @param PsrUri|string $uri Target address of websocket (e.g. ws://foo.bar/bar or
     * wss://crypto.example/?secureConnection) or a PsrUri instance.
     * @param HeaderParamArrayType $headers
     */
    public function __construct(PsrUri|string $uri, array $headers = [])
    {
        parent::__construct('GET', self::makeUri($uri));

        $this->setHeaders($headers);
    }

    /**
     * @return self Cloned object
     */
    public function withUri(PsrUri|string $uri): self
    {
        $clone = clone $this;
        $clone->setUri(self::makeUri($uri));

        return $clone;
    }

    /**
     * @return float Timeout in seconds for the TCP connection.
     */
    public function getTcpConnectTimeout(): float
    {
        return $this->tcpConnectTimeout;
    }

    public function withTcpConnectTimeout(float $tcpConnectTimeout): self
    {
        $clone = clone $this;
        $clone->tcpConnectTimeout = $tcpConnectTimeout;

        return $clone;
    }

    /**
     * @return float Timeout in seconds for the TLS handshake.
     */
    public function getTlsHandshakeTimeout(): float
    {
        return $this->tlsHandshakeTimeout;
    }

    public function withTlsHandshakeTimeout(float $tlsHandshakeTimeout): self
    {
        $clone = clone $this;
        $clone->tlsHandshakeTimeout = $tlsHandshakeTimeout;

        return $clone;
    }

    public function getHeaderSizeLimit(): int
    {
        return $this->headerSizeLimit;
    }

    public function withHeaderSizeLimit(int $headerSizeLimit): self
    {
        $clone = clone $this;
        $clone->headerSizeLimit = $headerSizeLimit;

        return $clone;
    }

    /**
     * Replaces all headers in the returned instance.
     *
     * @param HeaderParamArrayType $headers
     *
     * @return self Cloned object.
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->setHeaders($headers);

        return $clone;
    }

    /**
     * Replaces the given header in the returned instance.
     *
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @return self Cloned object.
     */
    public function withHeader(string $name, string|array $value): self
    {
        $clone = clone $this;
        $clone->setHeader($name, $value);

        return $clone;
    }

    /**
     * Adds the given header in the returned instance.
     *
     * @param non-empty-string $name
     * @param HeaderParamValueType $value
     *
     * @return self Cloned object.
     */
    public function withAddedHeader(string $name, string|array $value): self
    {
        $clone = clone $this;
        $clone->addHeader($name, $value);

        return $clone;
    }

    /**
     * Removes the given header in the returned instance.
     *
     * @return self Cloned object.
     */
    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        $clone->removeHeader($name);

        return $clone;
    }

    protected function setHeader(string $name, array|string $value): void
    {
        if (($name[0] ?? ':') === ':') {
            throw new \Error("Header name cannot be empty or start with a colon (:)");
        }

        parent::setHeader($name, $value);
    }

    protected function addHeader(string $name, array|string $value): void
    {
        if (($name[0] ?? ':') === ':') {
            throw new \Error("Header name cannot be empty or start with a colon (:)");
        }

        parent::addHeader($name, $value);
    }

    /**
     * @param QueryArrayType $parameters
     *
     * @return self Cloned object.
     */
    public function withQueryParameters(array $parameters): self
    {
        $clone = clone $this;
        $clone->setQueryParameters($parameters);

        return $clone;
    }

    /**
     * @param QueryValueType $value
     *
     * @return self Cloned object.
     */
    public function withQueryParameter(string $key, array|string|null $value): self
    {
        $clone = clone $this;
        $clone->setQueryParameter($key, $value);

        return $clone;
    }

    /**
     * @param QueryValueType $value
     *
     * @return self Cloned object.
     */
    public function withAddedQueryParameter(string $key, array|string|null $value): self
    {
        $clone = clone $this;
        $clone->addQueryParameter($key, $value);

        return $clone;
    }

    /**
     * @return self Cloned object.
     */
    public function withoutQueryParameter(string $key): self
    {
        $clone = clone $this;
        $clone->removeQueryParameter($key);

        return $clone;
    }

    /**
     * @return self Cloned object.
     */
    public function withoutQuery(): self
    {
        $clone = clone $this;
        $clone->removeQuery();

        return $clone;
    }

    private static function makeUri(PsrUri|string $uri): PsrUri
    {
        if (\is_string($uri)) {
            try {
                /** @psalm-suppress DeprecatedMethod Using deprecated method to support 6.x and 7.x of league/uri */
                $uri = Uri\Http::createFromString($uri);
            } catch (\Exception $exception) {
                throw new \ValueError('Invalid Websocket URI provided', 0, $exception);
            }
        }

        return match ($uri->getScheme()) {
            'ws', 'wss' => $uri,
            default => throw new \ValueError('The URI scheme must be ws or wss, got "' . $uri->getScheme() . '"'),
        };
    }
}
