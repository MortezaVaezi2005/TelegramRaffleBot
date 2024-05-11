<?php declare(strict_types=1);

namespace Amp\Websocket\Compression;

interface WebsocketCompressionContextFactory
{
    /**
     * Create a compression context from a header received from a websocket client request.
     *
     * @param string $headerIn Header from request.
     * @param-out string|null $headerOut Sec-Websocket-Extension response header if an instance
     * of {@see WebsocketCompressionContext} is returned, otherwise {@code null}.
     */
    public function fromClientHeader(string $headerIn, ?string &$headerOut): ?WebsocketCompressionContext;

    /**
     * Create a compression context from a header received from a websocket server response.
     *
     * @param string $header Header from response.
     */
    public function fromServerHeader(string $header): ?WebsocketCompressionContext;

    /**
     * @return string Header value for Sec-Websocket-Extension header.
     */
    public function createRequestHeader(): string;
}
