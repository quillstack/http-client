<?php

declare(strict_types=1);

namespace Quillstack\HttpClient;

use Quillstack\Response\Response;
use Quillstack\Response\StatusCode;

/**
 * What came back.
 *
 * A response written by an application says what it carries in `send()`; one which arrived
 * from somewhere else carries whatever was sent, which is its body.
 */
final class ClientResponse extends Response
{
    /**
     * {@inheritDoc}
     *
     * Nothing is assembled here: what came back is the body, and `getBody()` is where to
     * read it.
     */
    public function send(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     *
     * A status code nobody has heard of is somebody else's decision here, not a typo of ours
     * to refuse: a client which throws rather than hand back a `418` is a client that decides
     * which servers are allowed to exist. PSR-7 has an empty reason phrase mean there was
     * none, which is also the plain truth over HTTP/2 — that version dropped it entirely.
     */
    protected function findReasonPhrase(): string
    {
        return StatusCode::reasonPhrase($this->getStatusCode());
    }
}
