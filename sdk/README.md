# EchoRelay client libraries

One thin client per language, for the data path only:
`{baseUrl}/{line}/{endpoint}`. Managing a project — lines, endpoints, keys,
receipts — is the management API, whose document is `examples/openapi/`.

Each client is its own repository, checked out here as a submodule so this one
stays the single place to start:

| Library | Repository | Registry |
|---|---|---|
| TypeScript | [echorelay-typescript](https://github.com/EchoRelayDev/echorelay-typescript) | npm, `@echorelay/sdk` |
| Python | [echorelay-python](https://github.com/EchoRelayDev/echorelay-python) | PyPI, `echorelay` |
| PHP | [echorelay-php](https://github.com/EchoRelayDev/echorelay-php) | Packagist, `echorelay/sdk` |

```bash
git clone --recurse-submodules https://github.com/EchoRelayDev/echorelay.git
```

You do not need the submodules to run the examples — they call the service over
HTTP, and each library installs from its own registry.

## The clients are ports of one another

A client that decides for itself how to spell a header or when to raise is a
second answer to a question the relay has already answered, and the reader of
the second one cannot tell it is wrong. So the languages differ; the behaviour
does not.

`typescript/src/client.ts` is the reference implementation. A port mirrors it,
and where it cannot — an ecosystem's own convention for naming, errors or
concurrency wins over a literal transcription — it says so in its README.

What a port has to carry over, each one settled in the reference:

- How the address, the auth header and the default method are built, and what a
  caller's own header does to a default.
- Which failures raise, and what a raised error carries from the relay's error
  envelope. A transport failure that never reached the relay is distinguishable
  from one it answered.
- Redirects. The relay forwards a target's redirect verbatim, and a client that
  followed one would send the caller's key to a host the relay never named.
- What a timeout bounds. The endpoint's own wait is configured server-side and
  no client argument reaches it.
- Which call discards the target's answer and which returns it.

The relay accepts several inbound auth schemes; the reference client picks one
of them, and the API documentation lists the rest.

## Where the ports deliberately diverge from the reference

- **A `null` body.** The reference (`typescript/`) sends a caller's explicit
  `body: null` as the literal JSON `null` — an explicit `null` still isn't
  `undefined`, so it isn't skipped. Both current ports (`python/`, `php/`)
  instead read a null/`None` body as "send no body at all," and Python's own
  body type cannot express a literal `null` to begin with. This is recorded
  here, not just in each port's own README, so the next port picks one
  reading deliberately instead of guessing from `typescript/src/client.ts`.

## Verification

Each client's tests run on their own — no account, no network. The command for
one directory comes from `bin/verify-change plan --files sdk/<dir>/<file>`; nothing else in
this repository exercises them, and they ship to a package registry rather than
to our fleet, so a break here is found by whoever installs next.
