<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Factory;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Quillstack\HttpClient\Request;
use Quillstack\Uri\Factory\UriFactory;

class RequestFactory implements RequestFactoryInterface
{
    /**
     * The URI factory needs nothing itself, so one is made here where none was given: a
     * factory that cannot be built without first building another factory is a container's
     * job to assemble, and this is meant to work without one.
     */
    public function __construct(private readonly UriFactory $uriFactory = new UriFactory())
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : $this->uriFactory->createUri((string) $uri);
        $host = $uri->getHost();
        $port = $uri->getPort();

        // A request goes out with the host it is going to, which every server needs and
        // nobody should have to remember to set.
        $headers = $host === ''
            ? []
            : ['Host' => $port === null ? $host : "{$host}:{$port}"];

        return new Request($method, $uri, $headers);
    }
}
