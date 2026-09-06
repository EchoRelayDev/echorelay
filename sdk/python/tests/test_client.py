"""Tests for the EchoRelay Python client — pytest over httpx.MockTransport, no network, no account.

Behaviors that must hold for both classes are parametrized over `flavor`, which
runs the same test body once against `EchoRelay` (sync) and once against
`AsyncEchoRelay` (async): both share one private request builder
(`echorelay._request._RequestBuilder`), and this is what proves the two request
paths haven't drifted from each other.
"""

from __future__ import annotations

import asyncio
import json
from dataclasses import dataclass
from typing import Any, Callable, Dict, Optional

import httpx
import pytest

from echorelay import AsyncEchoRelay, EchoRelay, EchoRelayError

BASE_URL = "https://t.echorelay.cloud"
Handler = Callable[[httpx.Request], httpx.Response]


def json_response(status: int, body: Any, headers: Optional[Dict[str, str]] = None) -> httpx.Response:
    payload = json.dumps(body).encode("utf-8")
    return httpx.Response(status, content=payload, headers={"content-type": "application/json", **(headers or {})})


@dataclass
class Flavor:
    name: str
    make_relay: Callable[..., Any]
    call: Callable[[Any], Any]


def _sync_relay(handler: Handler, **client_kwargs: Any) -> EchoRelay:
    client = httpx.Client(transport=httpx.MockTransport(handler), **client_kwargs)
    return EchoRelay("secret-key", BASE_URL, http_client=client)


def _async_relay(handler: Handler, **client_kwargs: Any) -> AsyncEchoRelay:
    client = httpx.AsyncClient(transport=httpx.MockTransport(handler), **client_kwargs)
    return AsyncEchoRelay("secret-key", BASE_URL, http_client=client)


FLAVORS = [
    Flavor("sync", _sync_relay, lambda value: value),
    Flavor("async", _async_relay, asyncio.run),
]


@pytest.fixture(params=FLAVORS, ids=lambda f: f.name)
def flavor(request: pytest.FixtureRequest) -> Flavor:
    return request.param


@pytest.mark.parametrize("client_cls", [EchoRelay, AsyncEchoRelay])
def test_requires_api_key_and_base_url(client_cls: Any) -> None:
    with pytest.raises(ValueError):
        client_cls("", BASE_URL)
    with pytest.raises(ValueError):
        client_cls("k", "")


def test_send_posts_json_with_auth_header_and_resolves(flavor: Flavor) -> None:
    captured: Dict[str, Any] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["method"] = request.method
        captured["authorization"] = request.headers.get("authorization")
        captured["content-type"] = request.headers.get("content-type")
        captured["body"] = request.content
        return json_response(202, {"status": "queued"})

    relay = flavor.make_relay(handler)
    result = flavor.call(relay.send("v1", "/notify", body={"hello": "world"}))

    assert result is None
    assert captured["url"] == f"{BASE_URL}/v1/notify"
    assert captured["method"] == "POST"
    assert captured["authorization"] == "Bearer secret-key"
    assert captured["content-type"] == "application/json"
    assert captured["body"] == json.dumps({"hello": "world"}).encode("utf-8")


def test_send_omits_content_type_when_no_body(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.headers.get("content-type") is None
        assert request.content == b""
        return json_response(202, {"status": "queued"})

    relay = flavor.make_relay(handler)
    flavor.call(relay.send("v1", "/ping"))


def test_send_sync_returns_status_headers_and_parsed_json_body(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return json_response(200, {"enriched": True}, {"x-target-trace": "abc123"})

    relay = flavor.make_relay(handler)
    result = flavor.call(relay.send_sync("v1", "/enrich", body={"name": "ana"}))

    assert result.status == 200
    assert result.body == {"enriched": True}
    assert result.headers["x-target-trace"] == "abc123"


def test_send_sync_returns_a_non_json_body_as_text(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=b"plain text reply", headers={"content-type": "text/plain"})

    relay = flavor.make_relay(handler)
    result = flavor.call(relay.send_sync("v1", "/enrich"))

    assert result.body == "plain text reply"


def test_maps_a_non_2xx_to_error_with_relay_code_and_request_id(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return json_response(401, {"error": "key_revoked", "requestId": "11111111-1111-1111-1111-111111111111"})

    relay = flavor.make_relay(handler)
    with pytest.raises(EchoRelayError) as excinfo:
        flavor.call(relay.send("v1", "/notify", body={}))

    err = excinfo.value
    assert err.status == 401
    assert err.code == "key_revoked"
    assert err.request_id == "11111111-1111-1111-1111-111111111111"


def test_carries_a_message_for_an_error_response_with_an_empty_body(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(503)

    relay = flavor.make_relay(handler)
    with pytest.raises(EchoRelayError) as excinfo:
        flavor.call(relay.send("v1", "/notify"))

    err = excinfo.value
    assert err.status == 503
    assert err.code is None
    assert str(err) in ("Service Unavailable", "HTTP 503")


def test_a_transport_failure_maps_to_an_error_with_status_zero(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("connection refused", request=request)

    relay = flavor.make_relay(handler)
    with pytest.raises(EchoRelayError) as excinfo:
        flavor.call(relay.send("v1", "/notify"))

    err = excinfo.value
    assert err.status == 0
    assert err.code is None
    assert "connection refused" in str(err)


def test_a_callers_own_header_replaces_the_default_rather_than_appearing_twice(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.headers.get_list("content-type") == ["application/vnd.api+json"]
        assert request.headers["x-trace"] == "t-1"
        return json_response(202, {"status": "queued"})

    relay = flavor.make_relay(handler)
    flavor.call(
        relay.send(
            "v1",
            "/notify",
            body={"hello": "world"},
            headers={"content-type": "application/vnd.api+json", "X-Trace": "t-1"},
        )
    )


def test_refuses_a_non_positive_timeout_instead_of_passing_it_through(flavor: Flavor) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise AssertionError("the request must not be sent when the timeout is invalid")

    relay = flavor.make_relay(handler)
    for bad_timeout in (0, -1.0):
        with pytest.raises(ValueError, match="greater than 0"):
            flavor.call(relay.send("v1", "/notify", timeout=bad_timeout))


def test_a_redirect_is_returned_rather_than_followed(flavor: Flavor) -> None:
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        return httpx.Response(302, headers={"location": "https://elsewhere.test/x"})

    # The injected client is configured to follow redirects on its own — proving
    # the client only stops following because the SDK asserts `follow_redirects
    # =False` on every call, not because it happened to rely on a default.
    relay = flavor.make_relay(handler, follow_redirects=True)
    with pytest.raises(EchoRelayError) as excinfo:
        flavor.call(relay.send_sync("v1", "/enrich"))

    assert excinfo.value.status == 302
    assert len(calls) == 1
