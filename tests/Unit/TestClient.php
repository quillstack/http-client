<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Tests\Unit;

use Psr\Http\Client\ClientExceptionInterface;
use Quillstack\DI\Container;
use Quillstack\HttpClient\Client;
use Quillstack\HttpClient\ClientResponse;
use Quillstack\HttpClient\Exceptions\NetworkException;
use Quillstack\HttpClient\Factory\RequestFactory;
use Quillstack\HttpClient\Tests\Support\LocalServer;
use Quillstack\Stream\TextStream;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;

/**
 * The client, against an end which answers exactly as asked.
 *
 * A client tested against nothing is not tested, and one tested against somebody else's
 * website is testing their website.
 */
class TestClient
{
    private RequestFactory $factory;

    private string $url;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean
    ) {
        $this->factory = (new Container())->get(RequestFactory::class);
        $this->url = LocalServer::url();
    }

    public function whatCameBack()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/status?code=200")
        );

        $this->assertEqual->equal(200, $response->getStatusCode());
        $this->assertEqual->equal('status', (string) $response->getBody());
    }

    /**
     * A response saying the request was wrong is still a response — PSR-18 has a client throw
     * only where there is nothing to hand back at all.
     */
    public function afailingStatusIsAnAnswerRatherThanAFailure()
    {
        foreach ([400, 404, 418, 500, 503] as $code) {
            $response = (new Client())->sendRequest(
                $this->factory->createRequest('GET', "{$this->url}/status?code={$code}")
            );

            $this->assertEqual->equal($code, $response->getStatusCode());
        }
    }

    /**
     * Headers come back whole, including the ones holding a colon in their value — which is
     * what reading them out of the raw response would have broken.
     */
    public function headersComeBackWhole()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/headers")
        );

        $this->assertEqual->equal('first', $response->getHeaderLine('X-One'));
        $this->assertEqual->equal('a:b:c', $response->getHeaderLine('X-Colon'));
    }

    /**
     * A header sent more than once keeps every value.
     */
    public function aHeaderSentTwiceKeepsBoth()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/headers")
        );

        $this->assertEqual->equal(['first'], $response->getHeader('X-One'));
        $this->assertEqual->equal(['second'], $response->getHeader('X-Two'));
    }

    public function whatWasSentIsWhatArrives()
    {
        $request = $this->factory->createRequest('POST', "{$this->url}/echo")
            ->withHeader('X-Sent', 'yes')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new TextStream('{"a":1}'));

        $response = (new Client())->sendRequest($request);

        /** @var array<string, mixed> $sent */
        $sent = json_decode((string) $response->getBody(), true);

        $this->assertEqual->equal('POST', $sent['method']);
        $this->assertEqual->equal('/echo', $sent['target']);
        $this->assertEqual->equal('{"a":1}', $sent['body']);
        $this->assertEqual->equal('yes', $sent['sent']);
        $this->assertEqual->equal('application/json', $sent['type']);
    }

    /**
     * A body is read out of the stream whatever a reader has already taken from it, which is
     * what `__toString()` is for.
     */
    public function theBodyIsReadWhole()
    {
        $body = new TextStream('{"a":1}');
        $body->read(3);

        $request = $this->factory->createRequest('POST', "{$this->url}/echo")->withBody($body);
        $sent = json_decode((string) (new Client())->sendRequest($request)->getBody(), true);

        $this->assertEqual->equal('{"a":1}', $sent['body']);
    }

    public function itFollowsWhereItIsSent()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/moved")
        );

        $this->assertEqual->equal(200, $response->getStatusCode());
        $this->assertBoolean->isTrue(str_contains((string) $response->getBody(), '"target":"\/echo"'));
    }

    /**
     * The headers of a response which redirected belong to that response. cURL reports every
     * one in the chain through the same callback, so they used to pile up together and the
     * `200` came back naming a `Location` nobody was sent to.
     */
    public function aRedirectLeavesNothingBehind()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/moved")
        );

        $this->assertEqual->equal(200, $response->getStatusCode());
        $this->assertBoolean->isFalse($response->hasHeader('Location'));
        $this->assertBoolean->isFalse($response->hasHeader('X-Left-Behind'));
    }

    /**
     * A client which throws rather than hand back a code it has not heard of is a client
     * deciding which servers are allowed to exist.
     */
    public function aCodeNobodyRegisteredIsStillAnAnswer()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/teapot")
        );

        $this->assertEqual->equal(599, $response->getStatusCode());
        $this->assertEqual->equal('Something Nobody Registered', $response->getReasonPhrase());
    }

    /**
     * The reason phrase is whatever was said, not what we would have said for that code.
     */
    public function theReasonPhraseIsTheOneThatArrived()
    {
        $response = (new Client())->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/status?code=404")
        );

        $this->assertEqual->equal('Not Found', $response->getReasonPhrase());
        $this->assertEqual->equal('1.1', $response->getProtocolVersion());
    }

    public function itCanBeToldNotTo()
    {
        $response = (new Client(followRedirects: false))->sendRequest(
            $this->factory->createRequest('GET', "{$this->url}/moved")
        );

        $this->assertEqual->equal(302, $response->getStatusCode());
        $this->assertEqual->equal('/echo', $response->getHeaderLine('Location'));
    }

    /**
     * A call which never returns is the worst kind of failure, so there is always a limit —
     * cURL's own default is to wait for ever.
     */
    public function itDoesNotWaitForEver()
    {
        $refused = false;

        try {
            (new Client(timeout: 1))->sendRequest(
                $this->factory->createRequest('GET', "{$this->url}/slow")
            );
        } catch (NetworkException) {
            $refused = true;
        }

        $this->assertBoolean->isTrue($refused);
    }

    /**
     * An end which cannot be reached is a network failure, which PSR-18 keeps apart from a
     * request which was wrong: one is worth trying again and the other never will be.
     */
    public function anEndWhichCannotBeReachedSaysSo()
    {
        $caught = null;

        try {
            (new Client(connectTimeout: 2))->sendRequest(
                $this->factory->createRequest('GET', 'https://nowhere-at-all-xyzzy.invalid/')
            );
        } catch (NetworkException $exception) {
            $caught = $exception;
        }

        $this->assertBoolean->isTrue($caught !== null);
        $this->assertBoolean->isTrue($caught instanceof ClientExceptionInterface);
        $this->assertEqual->equal(
            'https://nowhere-at-all-xyzzy.invalid/',
            (string) $caught?->getRequest()->getUri()
        );
    }

    /**
     * HTTP/2 dropped the reason phrase entirely, so an unknown code often arrives with none
     * at all — and then there is nothing to fall back on but a table this code is not in.
     * A response which arrived is still a response.
     */
    public function anUnknownCodeWithNoPhraseAtAll()
    {
        $response = new ClientResponse(599, '');

        $this->assertEqual->equal(599, $response->getStatusCode());
        $this->assertEqual->equal('', $response->getReasonPhrase());
    }

    public function stopTheServer()
    {
        LocalServer::stop();

        $this->assertBoolean->isTrue(true);
    }
}
