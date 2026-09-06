# EchoRelay

Stop maintaining **adapter code**.

EchoRelay is a managed runtime for custom API integrations. Every request you
send goes to one address. What arrives is authenticated, validated, reshaped to
fit each target, and delivered.

You get back the target's own answer, or an acceptance and a **delivery
receipt** you can look up later.

Your integration is configuration you publish. A vendor changing their API means
updating a **mapping**, not redeploying your service.

## What is in this repository

| Directory | What it holds |
|---|---|
| `examples/` | Working configuration you can run against a real account, and the OpenAPI document for the API that manages your projects. |
| `sdk/` | The client libraries for sending requests: TypeScript, Python and PHP. |

Everything here is MIT licensed. Take it, change it, ship it.

## What is not in this repository

The service that runs your integrations. This repository is the configuration
and the client code you would otherwise write yourself, not the runtime behind
the address you send to.

## Getting started

You need an account. There is a **free tier**: https://echorelay.dev

Each example lists the environment it needs at the top of its script, and every
value comes from your own account. `examples/README.md` walks through them. The
only thing you run is `curl`, apart from the streaming example, which sends to a
streaming endpoint you supply.

## Clients

| Language | Package | Directory |
|---|---|---|
| TypeScript | `@echorelay/sdk` | `sdk/typescript/` |
| Python | `echorelay` | `sdk/python/` |
| PHP | `echorelay/sdk` | `sdk/php/` |

All three are ports of one another and behave the same way. `sdk/README.md`
records what that means and where they deliberately differ.

## Something here did not work

The OpenAPI document is checked against the live one, so it is current. If an
example is not, tell us. The address is on the site.
