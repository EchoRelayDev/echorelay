# EchoRelay examples

EchoRelay is a managed runtime for custom API integrations.

You point your senders at one address. What arrives is validated, reshaped into
the form your target expects, and sent on. You get back either the target's own
answer, or an acknowledgement and a delivery receipt you can look up afterwards.
The integration lives in configuration you publish, so there is no adapter
service of your own to build, deploy or keep running.

Everything here runs against a real account with a free tier. Nothing in this
directory is required to use the product; it is here so you can read working
configuration instead of prose.

## What is here

| Directory | What it shows |
|---|---|
| `resend-adapter/` | One inbound request, sent two ways: waiting for the target's answer, and queued with a delivery receipt to poll. `setup.sh` creates the line and its endpoints, `run.sh` sends through them. |
| `streaming/` | An endpoint whose target answers as a stream, passed back to your caller as it arrives. This one sends to a streaming endpoint you supply. |
| `openapi/management.json` | The document for the API that manages your project: lines, endpoints, keys, receipts. Regenerate it with `bin/regenerate-openapi-doc`. |

A TypeScript client for sending requests lives in `sdk/typescript/`. Use it
instead of hand-rolling the calls in `run.sh` if you work in that language.

## Running an example

Each script lists the environment it needs at the top of the file, and every
value comes from your own account: the address of your project, a management
token, and an inbound key. `streaming/` needs one thing more, the address and
credential of a streaming endpoint of yours to send to. The scripts create
things in your project and do not delete them, so use a project you are happy to
experiment in.

## Questions

**Do I need to run anything of my own?** For everything but `streaming/`, no:
the examples call the hosted service and the only thing you run is `curl`.
Streaming is answered by your target, so that example needs a streaming endpoint
of yours to send to.

**What am I allowed to do with this code?** Take it, change it, ship it. It is
here to be copied.

**Is this the whole product?** No. This directory is the configuration and the
client code you would write anyway. The service that runs your integrations is
not open source.

**Something here did not work.** The management API document in `openapi/` is
checked against the live one, so it is current. If an example is not, tell us:
the address is on the site.
