# echorelay/sdk

A lightweight PHP client for calling your EchoRelay project's address:
`https://<your-project>.echorelay.cloud/<line>/<endpoint>`.

- HTTP through [PSR-18](https://www.php-fig.org/psr/psr-18/) and
  [PSR-17](https://www.php-fig.org/psr/psr-17/), discovered with
  [`php-http/discovery`](https://github.com/php-http/discovery) — a Symfony or
  Laravel project keeps its own configured HTTP client instead of a second one
  this package would otherwise bundle.
- `"php": "^8.2"` — the floor for a library you install, not for what we deploy
  ourselves.

## Install

```bash
composer require echorelay/sdk guzzlehttp/guzzle
```

This package requires the PSR-18 and PSR-17 *interfaces* only, plus
`php-http/discovery` to find an implementation — it doesn't bundle one, so your
project needs to supply it. `guzzlehttp/guzzle` is the simplest way; a Symfony or
Laravel project that already carries `symfony/http-client` or another PSR-18
client needs nothing extra. Without either, the first `new EchoRelay(...)` throws,
naming what to install.

`php-http/discovery` runs as a Composer plugin; allow it once, when Composer asks
(or add `"allow-plugins": {"php-http/discovery": true}` to your `composer.json`
yourself).

## Usage

```php
use EchoRelay\Sdk\EchoRelay;

$relay = new EchoRelay(
    apiKey: getenv('ECHORELAY_KEY'),
    baseUrl: 'https://your-project.echorelay.cloud',
);
```

`baseUrl` is your project's own address, shown on its overview page in the panel —
there's no shared default, since every project has its own. Whether `apiKey` draws
from your live or test credit pool depends only on which key you pass (`er_live_…`
vs. `er_test_…`); the client has no separate flag that changes it.

### 1. Send a request

```php
$relay->send('v1', '/notify', ['body' => ['message' => 'order shipped']]);
```

Resolves once the relay accepts the call. Whether that means "queued for later
delivery" or "delivered and answered" depends on how you configured the `/notify`
endpoint's target in the panel — the SDK call is the same either way.

### 2. Send a request and read the target's reply

```php
$result = $relay->sendSync('v1', '/enrich', ['body' => ['name' => 'ana']]);
echo $result->status, ' ', json_encode($result->body);
```

`sendSync` returns the same call's response as a `SyncResult` (`status`, `headers`,
`body`) instead of discarding it — use it against an endpoint whose target is
configured to answer inline.

### 3. Handle errors

```php
use EchoRelay\Sdk\EchoRelayError;

try {
    $relay->send('v1', '/notify', ['body' => ['message' => 'order shipped']]);
} catch (EchoRelayError $e) {
    error_log("{$e->status} {$e->errorCode} {$e->getMessage()}");
}
```

Any non-2xx response throws an `EchoRelayError` carrying `status` (the HTTP status
code), `errorCode` (the relay's machine-readable reason, e.g. `key_revoked` or
`endpoint_not_found`, when the response included one), and `requestId` (for
support). A request that never reached the relay at all (DNS, network, or a local
timeout — anything the underlying client raises as a
`Psr\Http\Client\ClientExceptionInterface`) throws the same type with `status` set
to `0`.

## Options

`send` and `sendSync` both take an options array:

| Key | Meaning |
|---|---|
| `method` | HTTP method. Defaults to `POST`. |
| `body` | Serialized to JSON and sent with a `Content-Type: application/json` header. `null` means no body at all, not a JSON `null`. |
| `headers` | Extra headers, merged in after the auth header. A header you set here replaces the default of the same name rather than sitting beside it, case-insensitively — set `content-type` or `Content-Type` and either one wins alone. |
| `timeout` | Seconds to wait locally before giving up. Must be greater than 0 — a non-positive value throws `InvalidArgumentException` immediately rather than being passed through. This bounds only the SDK's own wait for the call — it is never sent to the relay and cannot change how long a target is waited on server-side. It reaches the client the same way the redirect setting does, so Guzzle and Symfony's adapter honor it; pass it with any other client and the call throws `InvalidArgumentException` rather than dropping it. |

## Bringing your own HTTP client

```php
use EchoRelay\Sdk\EchoRelay;

$relay = new EchoRelay(
    apiKey: getenv('ECHORELAY_KEY'),
    baseUrl: 'https://your-project.echorelay.cloud',
    httpClient: $myPsr18Client,
    requestFactory: $myPsr17RequestFactory,
    streamFactory: $myPsr17StreamFactory,
);
```

**Redirects are turned off wherever the SDK can reach the setting.** A
`sync`-configured endpoint's target can itself answer with a redirect, and the
relay forwards that response verbatim rather than chasing it — following it
from here would send your API key to a host the relay never named. PSR-18's
`sendRequest()` takes no per-call options, so the SDK turns redirect-following
off through the client's own API wherever one exists: Guzzle and Symfony's
PSR-18 adapter each expose one, and the SDK sets it on every call, including on
a client you injected with redirects enabled. Any other PSR-18 client can be
told nothing per call, so whether it follows a redirect is entirely down to how
it (or you) configured it — that configuration is the one thing the SDK cannot
override for you.

## Test mode

Whether `apiKey` draws from your live or test credit pool is decided purely by
which key you pass — the relay never reads a request flag for this. `EchoRelay`
still accepts a `testMode` constructor argument and exposes it as a readonly
`testMode` property, for callers who want to record which pool a given client
instance is meant to be used against; it has no effect on the wire.

## License

MIT.
