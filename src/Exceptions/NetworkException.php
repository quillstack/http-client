<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The other end could not be reached at all: no name, no route, no answer in time.
 *
 * PSR-18 keeps this apart from a request which was wrong, because they are acted on
 * differently — one is worth trying again and the other never will be.
 */
class NetworkException extends HttpClientException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * {@inheritDoc}
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
