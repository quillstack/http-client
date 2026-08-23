<?php

declare(strict_types=1);

namespace Quillstack\HttpClient;

use CurlHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quillstack\HeaderBag\HeaderBag;
use Quillstack\HttpClient\Exceptions\NetworkException;
use Quillstack\HttpClient\Exceptions\RequestException;
use Quillstack\Stream\TextStream;

/**
 * Sends a request and hands back what came.
 *
 * cURL does the sending, because it is already there. What this adds is the part cURL leaves
 * to the caller: telling a request which could not be sent from an end which could not be
 * reached, and never treating a `500` as a failure — a response is a response.
 */
class Client implements ClientInterface
{
    /**
     * How long to wait, in seconds. Something has to be said here: cURL's own default is to
     * wait for ever, and a call which never returns is the worst kind of failure.
     */
    public const TIMEOUT = 30;

    public const CONNECT_TIMEOUT = 10;

    /**
     * Used only where what arrived carried no status line to read one from.
     */
    public const DEFAULT_VERSION = '1.1';

    /**
     * @param array<int, mixed> $options anything else cURL should be told
     */
    public function __construct(
        private readonly int $timeout = self::TIMEOUT,
        private readonly int $connectTimeout = self::CONNECT_TIMEOUT,
        private readonly bool $followRedirects = true,
        private readonly array $options = []
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();

        if (!$handle instanceof CurlHandle) {
            throw new RequestException($request, 'Unable to start a request');
        }

        // Nothing is closed here on purpose: since PHP 8.0 the handle is an object, freed
        // when it goes out of scope, and `curl_close()` has done nothing but warn.
        return $this->send($handle, $request);
    }

    private function send(CurlHandle $handle, RequestInterface $request): ResponseInterface
    {
        /** @var array<string, string[]> $headers */
        $headers = [];

        /** @var array{version: string, code: int, reason: string}|null $status */
        $status = null;

        curl_setopt_array($handle, $this->options + [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $this->headerLines($request),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            // Headers are read here rather than with CURLOPT_HEADER, so that a header holding
            // a colon in its value stays whole.
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line) use (&$headers, &$status): int {
                $this->readHeader($line, $headers, $status);

                return strlen($line);
            },
        ] + $this->bodyOptions($request));

        $body = curl_exec($handle);
        $error = curl_error($handle);

        if ($body === false || $error !== '') {
            throw $this->failure($request, $handle, $error);
        }

        // A response saying the request was wrong is still a response: PSR-18 has a client
        // throw only where there is nothing to hand back at all.
        return new ClientResponse(
            $status['code'] ?? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $status['reason'] ?? '',
            new HeaderBag($headers),
            $status['version'] ?? self::DEFAULT_VERSION,
            new TextStream(is_string($body) ? $body : '')
        );
    }

    /**
     * Takes apart one line of what arrived.
     *
     * A status line starts a response, so a redirect that was followed does not leave its
     * headers on the one which finally answered: `Location` belongs to the `302`, and reading
     * it off the `200` would name somewhere nobody was sent.
     *
     * @param array<string, string[]>                            $headers
     * @param array{version: string, code: int, reason: string}|null $status
     */
    private function readHeader(string $line, array &$headers, ?array &$status): void
    {
        if (preg_match('#^HTTP/(\\S+)\\s+(\\d{3})\\s*(.*)$#', trim($line), $parts) === 1) {
            $headers = [];
            $status = [
                'version' => $parts[1],
                'code' => (int) $parts[2],
                // HTTP/2 dropped the reason phrase altogether, so there is often none to read.
                'reason' => trim($parts[3]),
            ];

            return;
        }

        $name = explode(':', $line, 2);

        if (count($name) === 2) {
            $headers[trim($name[0])][] = trim($name[1]);
        }
    }

    /**
     * Which of the two kinds of failure this was.
     *
     * PSR-18 keeps them apart because they are acted on differently: an end which could not
     * be reached is worth trying again, and a request which was wrong never will be.
     */
    private function failure(RequestInterface $request, CurlHandle $handle, string $error): NetworkException|RequestException
    {
        $code = curl_errno($handle);
        $message = $error === '' ? 'The request could not be sent' : $error;

        $network = [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ];

        return in_array($code, $network, true)
            ? new NetworkException($request, $message, $code)
            : new RequestException($request, $message, $code);
    }

    /**
     * @return string[]
     */
    private function headerLines(RequestInterface $request): array
    {
        $lines = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }

        return $lines;
    }

    /**
     * @return array<int, mixed>
     */
    private function bodyOptions(RequestInterface $request): array
    {
        $body = (string) $request->getBody();

        return $body === '' ? [] : [CURLOPT_POSTFIELDS => $body];
    }
}
