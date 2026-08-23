<?php

declare(strict_types=1);

namespace Quillstack\HttpClient;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Quillstack\HeaderBag\HeaderBag;
use Quillstack\Stream\TextStream;

/**
 * A request on its way out.
 *
 * `ServerRequest` is the one which arrived; this is the one being sent, which PSR-7 keeps
 * apart because a client has no `$_SERVER`, no cookies and no uploaded files to speak of.
 */
class Request implements RequestInterface
{
    private HeaderBag $headerBag;

    private StreamInterface $body;

    private string $requestTarget = '';

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        private string $protocolVersion = '1.1'
    ) {
        $this->method = strtoupper($method);
        $this->headerBag = new HeaderBag($headers);
        $this->body = $body ?? new TextStream();
    }

    /**
     * {@inheritDoc}
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * {@inheritDoc}
     */
    public function withProtocolVersion($version): static
    {
        $new = clone $this;
        $new->protocolVersion = (string) $version;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getHeaders(): array
    {
        return $this->headerBag->getHeaders();
    }

    /**
     * {@inheritDoc}
     */
    public function hasHeader($name): bool
    {
        return $this->headerBag->hasHeader($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getHeader($name): array
    {
        return $this->headerBag->getHeader($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getHeaderLine($name): string
    {
        return $this->headerBag->getHeaderLine($name);
    }

    /**
     * {@inheritDoc}
     */
    public function withHeader($name, $value): static
    {
        $new = clone $this;
        $new->headerBag = $this->headerBag->withHeader($name, $value);

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function withAddedHeader($name, $value): static
    {
        $new = clone $this;
        $new->headerBag = $this->headerBag->withAddedHeader($name, $value);

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function withoutHeader($name): static
    {
        $new = clone $this;
        $new->headerBag = $this->headerBag->withoutHeader($name);

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * {@inheritDoc}
     */
    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }

        $path = $this->uri->getPath();
        $target = $path === '' ? '/' : $path;
        $query = $this->uri->getQuery();

        return $query === '' ? $target : "{$target}?{$query}";
    }

    /**
     * {@inheritDoc}
     */
    public function withRequestTarget($requestTarget): static
    {
        $new = clone $this;
        $new->requestTarget = (string) $requestTarget;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * {@inheritDoc}
     */
    public function withMethod($method): static
    {
        $new = clone $this;
        $new->method = strtoupper((string) $method);

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * {@inheritDoc}
     */
    public function withUri(UriInterface $uri, $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        // PSR-7: with $preserveHost the Host header stands, unless there is not one to stand.
        if (!$preserveHost || !$this->hasHeader('Host')) {
            $host = $uri->getHost();

            if ($host !== '') {
                $port = $uri->getPort();
                $new->headerBag = $this->headerBag->withHeader(
                    'Host',
                    $port === null ? $host : "{$host}:{$port}"
                );
            }
        }

        return $new;
    }
}
