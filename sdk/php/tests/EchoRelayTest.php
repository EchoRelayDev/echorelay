<?php

declare(strict_types=1);

namespace EchoRelay\Sdk\Tests;

use EchoRelay\Sdk\EchoRelay;
use EchoRelay\Sdk\EchoRelayError;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class EchoRelayTest extends TestCase
{
    private const BASE_URL = 'https://t.echorelay.cloud';

    private function client(StubClient $stub): EchoRelay
    {
        $factory = new Psr17Factory();

        return new EchoRelay(
            apiKey: 'secret-key',
            baseUrl: self::BASE_URL,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EchoRelay('', self::BASE_URL);
    }

    public function testRequiresBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EchoRelay('k', '');
    }

    public function testStripsTrailingSlashesFromBaseUrlAndAddsALeadingSlashToEndpoint(): void
    {
        $stub = new StubClient(new Response(202));
        $factory = new Psr17Factory();
        $relay = new EchoRelay('k', 'https://t.echorelay.cloud///', $stub, $factory, $factory);

        $relay->send('v1', 'notify');

        self::assertSame('https://t.echorelay.cloud/v1/notify', (string) $stub->requests[0]->getUri());
    }

    public function testSendPostsJsonWithTheAuthHeaderAndResolvesOn202(): void
    {
        $stub = new StubClient(new Response(202, ['content-type' => 'application/json'], '{"status":"queued"}'));
        $relay = $this->client($stub);

        $relay->send('v1', '/notify', ['body' => ['hello' => 'world']]);

        self::assertCount(1, $stub->requests);
        $request = $stub->requests[0];
        self::assertSame('https://t.echorelay.cloud/v1/notify', (string) $request->getUri());
        self::assertSame('POST', $request->getMethod());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"hello":"world"}', (string) $request->getBody());
    }

    public function testSendSyncReturnsTheForwardedTargetsStatusHeadersAndParsedBody(): void
    {
        $stub = new StubClient(new Response(
            200,
            ['content-type' => 'application/json', 'x-target-trace' => 'abc123'],
            '{"enriched":true}',
        ));
        $relay = $this->client($stub);

        $result = $relay->sendSync('v1', '/enrich', ['body' => ['name' => 'ana']]);

        self::assertSame(200, $result->status);
        self::assertSame(['enriched' => true], $result->body);
        self::assertSame('abc123', $result->headers['x-target-trace']);
    }

    public function testSendSyncReturnsANonJsonBodyAsText(): void
    {
        $stub = new StubClient(new Response(200, ['content-type' => 'text/plain'], 'plain text reply'));
        $relay = $this->client($stub);

        $result = $relay->sendSync('v1', '/enrich', []);

        self::assertSame('plain text reply', $result->body);
    }

    public function testMapsA401ToATypedErrorWithStatusCodeAndRequestId(): void
    {
        $stub = new StubClient(new Response(
            401,
            ['content-type' => 'application/json'],
            '{"error":"key_revoked","requestId":"11111111-1111-1111-1111-111111111111"}',
        ));
        $relay = $this->client($stub);

        try {
            $relay->send('v1', '/notify', ['body' => []]);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(401, $e->status);
            self::assertSame('key_revoked', $e->errorCode);
            self::assertSame('11111111-1111-1111-1111-111111111111', $e->requestId);
        }
    }

    public function testMapsA500WithAFlatErrorEnvelopeToATypedError(): void
    {
        $stub = new StubClient(new Response(500, ['content-type' => 'application/json'], '{"error":"internal_error","requestId":"req-2"}'));
        $relay = $this->client($stub);

        try {
            $relay->sendSync('v1', '/enrich', []);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(500, $e->status);
            self::assertSame('internal_error', $e->errorCode);
            self::assertSame('req-2', $e->requestId);
        }
    }

    public function testMapsATransportFailureThatNeverReachedTheRelayToStatusZero(): void
    {
        $stub = new StubClient(throws: new StubClientException('connection refused'));
        $relay = $this->client($stub);

        try {
            $relay->send('v1', '/notify', []);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(0, $e->status);
            self::assertSame('connection refused', $e->getMessage());
            self::assertInstanceOf(StubClientException::class, $e->getPrevious());
        }
    }

    public function testOmitsContentTypeWhenNoBodyIsGiven(): void
    {
        $stub = new StubClient(new Response(202));
        $relay = $this->client($stub);

        $relay->send('v1', '/ping', []);

        $request = $stub->requests[0];
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testACallersOwnHeaderReplacesADefaultRatherThanAppearingTwice(): void
    {
        $stub = new StubClient(new Response(202));
        $relay = $this->client($stub);

        $relay->send('v1', '/notify', [
            'body' => ['hello' => 'world'],
            'headers' => ['content-type' => 'application/vnd.api+json', 'X-Trace' => 't-1'],
        ]);

        $request = $stub->requests[0];
        self::assertSame(['application/vnd.api+json'], $request->getHeader('Content-Type'));
        self::assertSame('t-1', $request->getHeaderLine('X-Trace'));
    }

    public function testCarriesAMessageForAnErrorResponseWithAnEmptyBody(): void
    {
        $stub = new StubClient(new Response(503, [], '', '1.1', 'Service Unavailable'));
        $relay = $this->client($stub);

        try {
            $relay->send('v1', '/notify', []);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(503, $e->status);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testTellsGuzzleNotToFollowRedirectsAndPassesTheTimeout(): void
    {
        $captured = [];
        $guzzle = new \GuzzleHttp\Client(['handler' => function ($request, array $options) use (&$captured) {
            $captured = $options;

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(202));
        }]);

        $factory = new Psr17Factory();
        $relay = new EchoRelay(
            apiKey: 'secret-key',
            baseUrl: self::BASE_URL,
            httpClient: $guzzle,
            requestFactory: $factory,
            streamFactory: $factory,
        );
        $relay->send('v1', '/notify', ['timeout' => 5.0]);

        // The caller injected a stock Guzzle client, which follows redirects
        // by default; the SDK overrides that on the call, not on the client.
        self::assertFalse($captured['allow_redirects']);
        self::assertSame(5.0, $captured['timeout']);
    }

    public function testGuzzleReportsA401AsARelayRejectionNotATransportFailure(): void
    {
        $guzzle = new \GuzzleHttp\Client(['handler' => function ($request, array $options) {
            // A stock Guzzle handler stack still runs its `http_errors`
            // middleware here — this only passes once the SDK disables it
            // per call, the same way Guzzle's own PSR-18 adapter does.
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
                401,
                ['content-type' => 'application/json'],
                '{"error":"key_revoked","requestId":"11111111-1111-1111-1111-111111111111"}',
            ));
        }]);

        $factory = new Psr17Factory();
        $relay = new EchoRelay(
            apiKey: 'secret-key',
            baseUrl: self::BASE_URL,
            httpClient: $guzzle,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $relay->send('v1', '/notify', ['body' => []]);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(401, $e->status);
            self::assertSame('key_revoked', $e->errorCode);
            self::assertSame('11111111-1111-1111-1111-111111111111', $e->requestId);
        }
    }

    public function testWrapsADiscoveryFailureWithAMessageNamingWhatToInstall(): void
    {
        $method = new \ReflectionMethod(EchoRelay::class, 'discover');
        $method->setAccessible(true);

        try {
            $method->invoke(null, function () {
                throw new \Http\Discovery\Exception\NotFoundException('no candidate found');
            });
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('composer require', $e->getMessage());
            self::assertInstanceOf(\Http\Discovery\Exception\NotFoundException::class, $e->getPrevious());
        }
    }

    public function testRefusesANonPositiveTimeout(): void
    {
        $stub = new StubClient(new Response(202));
        $relay = $this->client($stub);

        foreach ([0.0, -1.0] as $timeout) {
            try {
                $relay->send('v1', '/notify', ['timeout' => $timeout]);
                self::fail("expected InvalidArgumentException for timeout {$timeout}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('greater than 0', $e->getMessage());
            }
        }
    }

    public function testTellsSymfonyNotToFollowRedirectsAndPassesTheTimeout(): void
    {
        $captured = [];
        $mock = new \Symfony\Component\HttpClient\MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured) {
                $captured = $options;

                return new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 202]);
            },
        );

        $factory = new Psr17Factory();
        $relay = new EchoRelay(
            apiKey: 'secret-key',
            baseUrl: self::BASE_URL,
            httpClient: new \Symfony\Component\HttpClient\Psr18Client($mock, $factory, $factory),
            requestFactory: $factory,
            streamFactory: $factory,
        );
        $relay->send('v1', '/notify', ['timeout' => 5.0]);

        self::assertSame(0, $captured['max_redirects']);
        self::assertSame(5.0, $captured['max_duration']);
    }

    public function testRefusesATimeoutTheClientCannotHonor(): void
    {
        $stub = new StubClient(new Response(202));
        $relay = $this->client($stub);

        $this->expectException(\InvalidArgumentException::class);
        $relay->send('v1', '/notify', ['timeout' => 5.0]);
    }

    public function testSurfacesAForwardedRedirectInsteadOfFollowingIt(): void
    {
        $stub = new StubClient(new Response(302, ['location' => 'https://elsewhere.test/x']));
        $relay = $this->client($stub);

        try {
            $relay->sendSync('v1', '/enrich', []);
            self::fail('expected EchoRelayError');
        } catch (EchoRelayError $e) {
            self::assertSame(302, $e->status);
        }

        // EchoRelay never re-issues the request on a redirect status: whether
        // one gets followed is entirely the injected client's own decision,
        // settled before this response ever reaches EchoRelay.
        self::assertCount(1, $stub->requests);
    }
}
