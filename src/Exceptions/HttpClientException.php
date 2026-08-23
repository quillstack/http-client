<?php

declare(strict_types=1);

namespace Quillstack\HttpClient\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * What everything here throws, and what PSR-18 says a caller may catch.
 */
class HttpClientException extends RuntimeException implements ClientExceptionInterface
{
    //
}
