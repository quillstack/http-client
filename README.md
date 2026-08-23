# Quillstack HTTP Client

[![Tests](https://github.com/quillstack/http-client/actions/workflows/tests.yml/badge.svg)](https://github.com/quillstack/http-client/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/quillstack/http-client.svg)](https://packagist.org/packages/quillstack/http-client)
[![Downloads](https://img.shields.io/packagist/dt/quillstack/http-client.svg)](https://packagist.org/packages/quillstack/http-client)
[![PHP Version](https://img.shields.io/packagist/php-v/quillstack/http-client)](https://packagist.org/packages/quillstack/http-client)
[![StyleCI](https://github.styleci.io/repos/1343764491/shield?branch=main)](https://github.styleci.io/repos/1343764491?branch=main)
[![CodeFactor](https://www.codefactor.io/repository/github/quillstack/http-client/badge)](https://www.codefactor.io/repository/github/quillstack/http-client)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=quillstack_http-client&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=quillstack_http-client)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=quillstack_http-client&metric=coverage)](https://sonarcloud.io/summary/new_code?id=quillstack_http-client)
[![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_http-client&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_http-client)
[![Reliability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_http-client&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_http-client)
[![Security](https://sonarcloud.io/api/project_badges/measure?project=quillstack_http-client&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_http-client)
[![License](https://img.shields.io/packagist/l/quillstack/http-client)](https://github.com/quillstack/http-client/blob/main/LICENSE)

An HTTP client based on PSR-18, over cURL, which tells a request that could not be sent from an
end that could not be reached. Full documentation: https://quillstack.org/http-client

cURL will do the sending — it is already there, and it is good at it. What it leaves to the
caller is everything that matters afterwards: whether the thing that went wrong is worth trying
again, and whether anything came back at all. **A client which throws on a `404` has decided
that a server answering you is a failure**, and an application written against it grows a
`try`/`catch` around every call it makes.

## Why this exists

cURL already sends HTTP requests, and it is good at it. What it leaves entirely to the caller is
everything that decides what an application does next: **whether the thing that went wrong is
worth trying again**, and whether anything came back at all.

PSR-18 draws that line — a client throws only where there is nothing to hand back — and most
clients cross it anyway. A `404` is an answer; a client which throws on one has decided that a
server replying to you is a failure, and an application written against it grows a `try`/`catch`
around every call it makes.

So: a network that could not be reached is a `NetworkException`, a request that could never be
sent is a `RequestException`, and every response the server actually sent comes back as a
response — including the status codes nobody registered.

It is also small, which is not the point but is the consequence. See the
[benchmark](#benchmark).

## Requirements

- PHP 8.1 or newer
- The cURL extension

## Installation

```shell
composer require quillstack/http-client
```

## Usage

```php
use Quillstack\HttpClient\Client;
use Quillstack\HttpClient\Factory\RequestFactory;

$factory = new RequestFactory();
$client = new Client();

$response = $client->sendRequest(
    $factory->createRequest('GET', 'https://example.org/')
);

$response->getStatusCode();          // 200
$response->getReasonPhrase();        // 'OK'
$response->getHeaderLine('Content-Type');
(string) $response->getBody();
```

Both are PSR-17 and PSR-18, so an application which does not want to name them can say so once
and ask for the interfaces everywhere else:

```php
$container = new Container([
    RequestFactoryInterface::class => RequestFactory::class,
    ClientInterface::class => Client::class,
]);

$client = $container->get(ClientInterface::class);
```

Nothing in this package requires a container, and nothing in it requires you to avoid one.

### Sending something

A request is a PSR-7 message: every `with...()` hands back a new one, and the original is
untouched.

```php
use Quillstack\Stream\TextStream;

$request = $factory->createRequest('POST', 'https://example.org/users')
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Authorization', "Bearer {$token}")
    ->withBody(new TextStream(json_encode(['name' => 'Ada'])));

$response = $client->sendRequest($request);
```

`Host` is set from the URI when the request is made, so it is right without being written down.

### A response is a response

```php
$response = $client->sendRequest(
    $factory->createRequest('GET', 'https://example.org/nothing-here')
);

$response->getStatusCode();   // 404 — and nothing was thrown
```

PSR-18 has a client throw only where there is nothing to hand back at all. A `404`, a `422` and
a `500` are all answers, and an application decides what they mean; a status code this library
has never heard of is somebody else's decision too, and comes back as it arrived:

```php
$response->getStatusCode();     // 599
$response->getReasonPhrase();   // whatever the server called it
```

### The two kinds of failure

```php
use Quillstack\HttpClient\Exceptions\NetworkException;
use Quillstack\HttpClient\Exceptions\RequestException;

try {
    $response = $client->sendRequest($request);
} catch (NetworkException $exception) {
    // The end could not be reached: DNS, a refused connection, a timeout, a broken
    // handshake. Worth trying again in a moment.
    $exception->getMessage();          // 'Could not resolve host: example.invalid'
    $exception->getRequest();          // the request that did not get through
} catch (RequestException $exception) {
    // The request itself could not be sent, and never will be. Trying again changes nothing.
}
```

Both carry the request, both are `Psr\Http\Client\ClientExceptionInterface`, so catching that
one catches everything this client throws.

### Timeouts

cURL's own default is to wait for ever, and a call which never returns is the worst kind of
failure — the one with nothing in the log. There is always a limit here, and it can be moved:

```php
$client = new Client(
    timeout: 5,             // seconds for the whole call, 30 by default
    connectTimeout: 2,      // seconds to reach the other end, 10 by default
    followRedirects: false, // true by default
);
```

A timeout arrives as a `NetworkException`, because that is what it is.

Anything else cURL understands goes in as options, which are applied before everything above so
that nothing here can be quietly overridden:

```php
$client = new Client(options: [
    CURLOPT_PROXY => 'http://proxy.internal:3128',
]);
```

### Redirects

They are followed by default, and the response you get is the one that finally answered:

```php
$response = $client->sendRequest($factory->createRequest('GET', 'https://example.org/moved'));

$response->getStatusCode();          // 200, from wherever it ended up
$response->hasHeader('Location');    // false — that header belonged to the 302
```

cURL reports every response in a chain, redirects included, so headers pile up unless somebody
separates them. Reading `Location` off the `200` would name somewhere nobody was sent.

```php
$response = (new Client(followRedirects: false))->sendRequest($request);

$response->getStatusCode();             // 302
$response->getHeaderLine('Location');   // '/echo'
```

### What is tested against

The suite runs a small PHP server of its own and asks it for exactly what each test needs: a
chosen status code, a header holding a colon in its value, an echo of what arrived, a redirect,
and a response that comes too late. A client tested against nothing is not tested, and one
tested against somebody else's website is testing their website.

## Benchmark

Measured with [quillstack/benchmark](https://github.com/quillstack/benchmark) against a local
server which answers the same nineteen bytes every time — what is being measured is the client,
not the network or the server. Runs are interleaved, each figure is the median of five, and PHP
is 8.5.7.

| | Version |
| --- | --- |
| quillstack/http-client | v0.7.0 |
| symfony/http-client | v7.4.17 |
| guzzlehttp/guzzle | 7.15.3 |

**One request in a fresh process** — which is what a PHP request does when it calls out to
something:

| | Time | Relative | Files loaded | Memory |
| --- | --- | --- | --- | --- |
| **quillstack/http-client** | **2.27 ms** | — | 24 | 292 kB |
| symfony/http-client | 3.39 ms | 1.5× | 19 | 592 kB |
| guzzlehttp/guzzle | 7.69 ms | 3.4× | 44 | 1792 kB |

**A hundred requests from a warm process**, per request:

| | Per request | Relative |
| --- | --- | --- |
| **quillstack/http-client** | **222 µs** | — |
| symfony/http-client | 255 µs | 1.15× |
| guzzlehttp/guzzle | 259 µs | 1.17× |

The second table is nearly flat on purpose: once a process is warm, almost all of that quarter
of a millisecond is the round trip, and no client can do anything about that. **The difference
between these libraries shows up in the first table and nowhere else** — it is what each of them
loads before it can send anything, and 1.8 MB is a lot to read to fetch nineteen bytes.

**What the numbers do not say:** Guzzle has middleware, promises, concurrent pools, retries and
cookie handling; `symfony/http-client` has HTTP/2, connection pooling and streaming responses.
This has none of that. It sends a request and gives back what came, and being faster because you
do less is not being faster — it is being smaller, which is a different thing to want.

## Tests

```shell
composer test
composer stan
```

## The rest of Quillstack

This is one component of [Quillstack](https://github.com/quillstack), a PHP framework which is
as simple to use as it is strict about what it does. Everything here is standards-first: the
only third-party code in this package is the PSR interfaces themselves.

- [quillstack/uri](https://github.com/quillstack/uri) — PSR-7 URIs
- [quillstack/stream](https://github.com/quillstack/stream) — PSR-7 streams
- [quillstack/response](https://github.com/quillstack/response) — PSR-7 responses
- [quillstack/header-bag](https://github.com/quillstack/header-bag) — the headers underneath

## License

MIT — see [LICENSE](LICENSE).
