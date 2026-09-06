# echorelay

A lightweight Python client for calling your EchoRelay project's address:
`https://<your-project>.echorelay.cloud/<line>/<endpoint>`.

- One runtime dependency, [`httpx`](https://www.python-httpx.org/) — it serves
  both the sync and the async client from one implementation.
- `py.typed` shipped in the wheel.
- `requires-python = ">=3.9"`.

## Install

```bash
pip install echorelay
```

## Usage

```python
import os
from echorelay import EchoRelay

relay = EchoRelay(
    api_key=os.environ["ECHORELAY_KEY"],
    base_url="https://your-project.echorelay.cloud",
)
```

`base_url` is your project's own address, shown on its overview page in the panel —
there's no shared default, since every project has its own. Whether `api_key` draws
from your live or test credit pool depends only on which key you pass (`er_live_…`
vs. `er_test_…`); the client has no separate flag that changes it.

### 1. Send a request

```python
relay.send("v1", "/notify", body={"message": "order shipped"})
```

Returns once the relay accepts the call. Whether that means "queued for later
delivery" or "delivered and answered" depends on how you configured the `/notify`
endpoint's target in the panel — the SDK call is the same either way.

### 2. Send a request and read the target's reply

```python
result = relay.send_sync("v1", "/enrich", body={"name": "ana"})
print(result.status, result.body)
```

`send_sync` returns the same call's response as a `SyncResult(status, headers,
body)` instead of discarding it — use it against an endpoint whose target is
configured to answer inline.

### 3. Handle errors

```python
from echorelay import EchoRelayError

try:
    relay.send("v1", "/notify", body={"message": "order shipped"})
except EchoRelayError as err:
    print(err.status, err.code, err)
```

Any non-2xx response raises an `EchoRelayError` carrying `status` (the HTTP status
code), `code` (the relay's machine-readable reason, e.g. `key_revoked` or
`endpoint_not_found`, when the response included one), and `request_id` (for
support). A request that never reached the relay at all (DNS, network, or a local
timeout) raises the same type with `status` set to `0`.

### 4. The async client

`AsyncEchoRelay` mirrors `EchoRelay` method for method, backed by
`httpx.AsyncClient` instead of `httpx.Client`:

```python
import asyncio
from echorelay import AsyncEchoRelay

async def main() -> None:
    relay = AsyncEchoRelay(
        api_key=os.environ["ECHORELAY_KEY"],
        base_url="https://your-project.echorelay.cloud",
    )
    try:
        result = await relay.send_sync("v1", "/enrich", body={"name": "ana"})
        print(result.status, result.body)
    finally:
        await relay.aclose()

asyncio.run(main())
```

Both clients are also context managers (`with EchoRelay(...) as relay:` /
`async with AsyncEchoRelay(...) as relay:`), which close the underlying `httpx`
client on exit — unless you inject your own via `http_client`, in which case you
own its lifecycle and the SDK never closes it for you.

## Options

`send` and `send_sync` both take:

| Option | Meaning |
|---|---|
| `method` | HTTP method. Defaults to `POST`. |
| `body` | Serialized to JSON and sent with a `Content-Type: application/json` header. `None` means no body at all, not a JSON `null`. |
| `headers` | Extra headers, merged in after the auth header. |
| `timeout` | How long to wait locally before giving up. A plain number must be greater than 0 — a non-positive value raises `ValueError` immediately rather than being passed through. This bounds only the SDK's own HTTP call — it is not sent to the relay and does not change how long a target is waited on server-side. A number is httpx's own timeout, which budgets connect, read, write and pool separately rather than as one deadline; pass an `httpx.Timeout` to set those yourself, or leave it out and the client you constructed decides. |

The constructor also takes `http_client`: an already-configured `httpx.Client`
(for `EchoRelay`) or `httpx.AsyncClient` (for `AsyncEchoRelay`) to send requests
through instead of one the SDK creates for you — useful for connection pooling
across relay clients, or for injecting a test transport.

## License

MIT.
