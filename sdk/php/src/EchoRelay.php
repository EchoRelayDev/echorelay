<?php

declare(strict_types=1);

namespace EchoRelay\Sdk;

use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin client for an EchoRelay project's data path
 * (`{baseUrl}/{line}/{endpoint}`).
 *
 * `send` and `sendSync` issue the identical HTTP call — method, headers, and
 * body are the same either way. What comes back (an immediate 202, or the
 * target's own forwarded response) is decided by how the endpoint's target
 * is configured server-side, not by which method you call; `sendSync`
 * additionally parses and returns that response instead of discarding it.
 *
 * The API key travels as `Authorization: Bearer {apiKey}` on every call.
 */
final class EchoRelay
{
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /** Stored as given; carries no wire effect — see the README's "Test mode" note. */
    public readonly bool $testMode;

    public function __construct(
        string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        bool $testMode = false,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('EchoRelay: apiKey is required');
        }
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('EchoRelay: baseUrl is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->testMode = $testMode;
        $this->httpClient = $httpClient ?? self::discover(static fn () => Psr18ClientDiscovery::find());
        $this->requestFactory = $requestFactory ?? self::discover(static fn () => Psr17FactoryDiscovery::findRequestFactory());
        $this->streamFactory = $streamFactory ?? self::discover(static fn () => Psr17FactoryDiscovery::findStreamFactory());
    }

    /**
     * `composer.json` requires the PSR-18/PSR-17 interfaces and discovery
     * only, never an implementation — a Symfony or Laravel project keeps its
     * own instead of a second one this package would otherwise bundle. When
     * nothing satisfies discovery, its own failure names discovery's
     * vocabulary (strategies, candidates); this wraps it with the one thing
     * a reader of this package actually needs: what to install.
     */
    private static function discover(callable $find): mixed
    {
        try {
            return $find();
        } catch (DiscoveryException $e) {
            throw new \RuntimeException(
                'EchoRelay: no PSR-18 HTTP client (and PSR-17 factories) is installed. Install one — '
                . 'e.g. `composer require guzzlehttp/guzzle` — or pass httpClient, requestFactory, and '
                . 'streamFactory to the constructor yourself.',
                previous: $e,
            );
        }
    }

    /**
     * Fire a request; resolves once the relay accepts it, discarding the
     * response body.
     *
     * @param array{method?: string, body?: mixed, headers?: array<string, string>, timeout?: float} $options
     */
    public function send(string $line, string $endpoint, array $options = []): void
    {
        // An unread body holds its connection open until the client collects it.
        $this->dispatch($line, $endpoint, $options)->getBody()->close();
    }

    /**
     * Fire a request and return the forwarded target response: status,
     * headers, and parsed body.
     *
     * @param array{method?: string, body?: mixed, headers?: array<string, string>, timeout?: float} $options
     */
    public function sendSync(string $line, string $endpoint, array $options = []): SyncResult
    {
        $response = $this->dispatch($line, $endpoint, $options);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $text = (string) $response->getBody();
        $body = null;
        if ($text !== '') {
            $decoded = json_decode($text, true);
            // A `null` decode is ambiguous between literal JSON `null` and a
            // parse failure; re-check with json_last_error() rather than
            // guess, since the raw text is the correct fallback either way.
            $body = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $text;
        }

        return new SyncResult($response->getStatusCode(), $headers, $body);
    }

    private function dispatch(string $line, string $endpoint, array $options): ResponseInterface
    {
        $path = str_starts_with($endpoint, '/') ? $endpoint : '/' . $endpoint;
        $url = "{$this->baseUrl}/{$line}{$path}";

        $timeout = $options['timeout'] ?? null;
        if ($timeout !== null && $timeout <= 0) {
            throw new \InvalidArgumentException('EchoRelay: timeout must be greater than 0');
        }

        $request = $this->requestFactory
            ->createRequest($options['method'] ?? 'POST', $url)
            ->withHeader('Authorization', "Bearer {$this->apiKey}");

        if (array_key_exists('body', $options) && $options['body'] !== null) {
            $json = json_encode($options['body'], JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        // A caller's own spelling of a header replaces the default instead of
        // appearing beside it: PSR-7's withHeader() is itself
        // case-insensitive on the header name, so no extra bookkeeping is
        // needed here the way the TypeScript client needs a `Headers` object.
        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->sendRequest($request, $timeout);
        } catch (ClientExceptionInterface $e) {
            throw new EchoRelayError($e->getMessage(), 0, previous: $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw EchoRelayError::fromResponse($response);
        }

        return $response;
    }

    /**
     * The relay forwards a sync target's redirect verbatim, and following one
     * would send this request's credentials to a host the relay never named —
     * so no call follows a redirect, whoever supplied the client. `$timeout`
     * bounds only how long this call waits locally; the endpoint's own wait is
     * decided server-side and nothing passed here reaches it.
     *
     * PSR-18's `sendRequest()` carries no per-call options, so both are
     * applied through the client's own API. Guzzle and Symfony's adapter each
     * expose one, and whichever of them is installed is also what discovery
     * returns when no client is injected. Every other client discovery can
     * find already declines redirects unless a plugin adds one, and can be
     * told nothing per call — so a timeout there is refused rather than
     * quietly dropped.
     *
     * Guzzle's own `http_errors` defaults to `true`, which raises a non-2xx
     * response as a `GuzzleException` — itself a `ClientExceptionInterface`,
     * so `dispatch()`'s catch above would mistake a relay rejection for a
     * transport failure that never reached the relay. Disabling it here is
     * what Guzzle's own PSR-18 adapter does, so a non-2xx response comes back
     * as a normal `ResponseInterface` for `dispatch()` to map from the
     * relay's own error envelope instead.
     */
    private function sendRequest(RequestInterface $request, ?float $timeout): ResponseInterface
    {
        if ($this->httpClient instanceof \GuzzleHttp\ClientInterface) {
            $options = ['allow_redirects' => false, 'http_errors' => false];
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
            }

            return $this->httpClient->send($request, $options);
        }

        if ($this->httpClient instanceof \Symfony\Component\HttpClient\Psr18Client) {
            $options = ['max_redirects' => 0];
            if ($timeout !== null) {
                $options['max_duration'] = $timeout;
            }

            return $this->httpClient->withOptions($options)->sendRequest($request);
        }

        if ($timeout !== null) {
            throw new \InvalidArgumentException(sprintf(
                'EchoRelay: %s exposes no per-call timeout, so the `timeout` option cannot be honored. '
                . 'Configure the wait on the client itself, and omit `timeout` here.',
                $this->httpClient::class,
            ));
        }

        return $this->httpClient->sendRequest($request);
    }
}
