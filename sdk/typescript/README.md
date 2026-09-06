# @echorelay/sdk

A lightweight TypeScript/JavaScript client for calling your EchoRelay project's
address: `https://<your-project>.echorelay.cloud/<line>/<endpoint>`.

- Zero runtime dependencies.
- Ships both an ESM and a CommonJS build, plus TypeScript types.
- Works anywhere `fetch` is available: Node 18+, browsers, and edge runtimes —
  with one caveat: in a browser, a `sync`-configured endpoint whose target
  answers with a redirect comes back from `fetch` as an opaque response
  (`status: 0`, no headers, no body), indistinguishable from a request that
  never reached the relay at all. Node and other non-browser runtimes report
  the redirect's real status instead.

## Install

```bash
npm install @echorelay/sdk
```

## Usage

```ts
import { EchoRelay } from "@echorelay/sdk";

const relay = new EchoRelay({
  apiKey: process.env.ECHORELAY_KEY!,
  baseUrl: "https://your-project.echorelay.cloud",
});
```

`baseUrl` is your project's own address, shown on its overview page in the panel —
there's no shared default, since every project has its own. Whether `apiKey` draws
from your live or test credit pool depends only on which key you pass (`er_live_…`
vs. `er_test_…`); the client has no separate flag that changes it.

### 1. Send a request

```ts
await relay.send("v1", "/notify", { body: { message: "order shipped" } });
```

Resolves once the relay accepts the call. Whether that means "queued for later
delivery" or "delivered and answered" depends on how you configured the `/notify`
endpoint's target in the panel — the SDK call is the same either way.

### 2. Send a request and read the target's reply

```ts
const result = await relay.sendSync("v1", "/enrich", { body: { name: "ana" } });
console.log(result.status, result.body);
```

`sendSync` returns the same call's response as `{ status, headers, body }` instead
of discarding it — use it against an endpoint whose target is configured to answer
inline.

### 3. Handle errors

```ts
import { EchoRelayError } from "@echorelay/sdk";

try {
  await relay.send("v1", "/notify", { body: { message: "order shipped" } });
} catch (err) {
  if (err instanceof EchoRelayError) {
    console.error(err.status, err.code, err.message);
  }
}
```

Any non-2xx response throws an `EchoRelayError` carrying `status` (the HTTP status
code), `code` (the relay's machine-readable reason, e.g. `key_revoked` or
`endpoint_not_found`, when the response included one), and `requestId` (for
support). A request that never reached the relay at all (DNS, network, or a local
timeout) throws the same type with `status` set to `0`.

## Options

`send` and `sendSync` both take:

| Option | Meaning |
|---|---|
| `method` | HTTP method. Defaults to `POST`. |
| `body` | Serialized to JSON and sent with a `Content-Type: application/json` header. |
| `headers` | Extra headers, merged in after the auth header. |
| `timeout` | Seconds to wait locally before aborting. Must be greater than 0 — a non-positive value throws immediately rather than being silently ignored. This bounds only the SDK's own `fetch` call, body included — it is not sent to the relay and does not change how long a target is waited on server-side. |

## License

MIT.
