<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Exceptions;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The request itself could not be sent: something about it was wrong before anything was
 * tried. Sending it again would go the same way.
 */
class RequestException extends HttpClientException implements RequestExceptionInterface
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
