<?php

declare(strict_types=1);

namespace EchoRelay\Sdk\Tests;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client double that hands back one pre-built response, or throws
 * one pre-built transport failure, and records every request it was given —
 * so a test can assert on both without a network or a live account.
 */
final class StubClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(
        private readonly ?ResponseInterface $response = null,
        private readonly ?ClientExceptionInterface $throws = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->response;
    }
}
