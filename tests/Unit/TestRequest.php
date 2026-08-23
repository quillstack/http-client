<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Tests\Unit;

use Quillstack\DI\Container;
use Quillstack\HttpClient\Factory\RequestFactory;
use Quillstack\HttpClient\Request;
use Quillstack\Stream\TextStream;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestRequest
{
    private RequestFactory $factory;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        $this->factory = (new Container())->get(RequestFactory::class);
    }

    public function whatWasAskedFor()
    {
        $request = $this->factory->createRequest('get', 'https://example.org/users?page=2');

        // The method is what HTTP calls it, whatever case it was written in.
        $this->assertEqual->equal('GET', $request->getMethod());
        $this->assertEqual->equal('/users?page=2', $request->getRequestTarget());
        $this->assertEqual->equal('example.org', $request->getUri()->getHost());
    }

    /**
     * A request goes out with the host it is going to, which every server needs and nobody
     * should have to remember to set.
     */
    public function theHostIsThereWithoutBeingAskedFor()
    {
        $this->assertEqual->equal(
            'example.org',
            $this->factory->createRequest('GET', 'https://example.org/')->getHeaderLine('Host')
        );
        $this->assertEqual->equal(
            'example.org:8443',
            $this->factory->createRequest('GET', 'https://example.org:8443/')->getHeaderLine('Host')
        );
    }

    /**
     * A URI with nothing after the host asks for the root, not for nothing.
     */
    public function nothingAfterTheHostIsTheRoot()
    {
        $this->assertEqual->equal('/', $this->factory->createRequest('GET', 'https://example.org')->getRequestTarget());
    }

    /**
     * Every change hands back a copy, so a request handed to something else cannot change
     * the one you kept.
     */
    public function everyChangeIsACopy()
    {
        $request = $this->factory->createRequest('GET', 'https://example.org/');
        $changed = $request
            ->withMethod('post')
            ->withHeader('X-Sent', 'yes')
            ->withBody(new TextStream('{"a":1}'));

        $this->assertEqual->equal('GET', $request->getMethod());
        $this->assertEqual->equal('', $request->getHeaderLine('X-Sent'));
        $this->assertEqual->equal('', (string) $request->getBody());

        $this->assertEqual->equal('POST', $changed->getMethod());
        $this->assertEqual->equal('yes', $changed->getHeaderLine('X-Sent'));
        $this->assertEqual->equal('{"a":1}', (string) $changed->getBody());
    }

    public function headersAreReadWithoutRegardToCase()
    {
        $request = $this->factory->createRequest('GET', 'https://example.org/')
            ->withHeader('Content-Type', 'application/json');

        $this->assertEqual->equal('application/json', $request->getHeaderLine('content-type'));
        $this->assertBoolean->isTrue($request->hasHeader('CONTENT-TYPE'));
    }

    public function aTargetCanBeSaidOutright()
    {
        $request = $this->factory->createRequest('GET', 'https://example.org/users')
            ->withRequestTarget('*');

        $this->assertEqual->equal('*', $request->getRequestTarget());
    }

    /**
     * Moving a request to another URI takes the new host with it, unless it is asked to keep
     * the one it had — which is what PSR-7 says of it.
     */
    public function movingItTakesTheHostAlong()
    {
        $request = $this->factory->createRequest('GET', 'https://example.org/');
        $elsewhere = (new Container())->get(\Quillstack\Uri\Factory\UriFactory::class)
            ->createUri('https://other.example/');

        $this->assertEqual->equal('other.example', $request->withUri($elsewhere)->getHeaderLine('Host'));
        $this->assertEqual->equal('example.org', $request->withUri($elsewhere, true)->getHeaderLine('Host'));
    }

    public function oneCanBeBuiltByHand()
    {
        $uri = (new Container())->get(\Quillstack\Uri\Factory\UriFactory::class)
            ->createUri('https://example.org/things');

        $request = new Request('PUT', $uri, ['X-One' => 'first'], new TextStream('body'));

        $this->assertEqual->equal('PUT', $request->getMethod());
        $this->assertEqual->equal('first', $request->getHeaderLine('X-One'));
        $this->assertEqual->equal('body', (string) $request->getBody());
        $this->assertEqual->equal('1.1', $request->getProtocolVersion());
    }
}
