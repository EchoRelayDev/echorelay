"""Shared request assembly for `EchoRelay` and `AsyncEchoRelay`.

The URL, the headers, and the timeout are built once here and used by both
clients; the error mapping is shared via `errors.error_from_response`. Kept
private (leading underscore, not part of `echorelay.__init__`'s surface) since
its only callers are the two client classes in `client.py`.
"""

from __future__ import annotations

import json
from typing import Any, Dict, List, Mapping, Optional, Tuple, Union

import httpx

from .errors import EchoRelayError, error_from_response

# `None` is absent, not a JSON `null`: a Python caller reads `body=None` as
# "no body", and a type that also promised a literal null would be promising
# something no call can express.
JsonBody = Union[Dict[str, Any], List[Any], str, int, float, bool]


class _RequestBuilder:
    def __init__(self, api_key: str, base_url: str, test_mode: bool = False) -> None:
        if not api_key:
            raise ValueError("EchoRelay: api_key is required")
        if not base_url:
            raise ValueError("EchoRelay: base_url is required")
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        # Stored as given; carries no wire effect — the relay decides live vs.
        # test purely from which key you pass, never from a request flag.
        self.test_mode = test_mode

    def prepare(
        self,
        line: str,
        endpoint: str,
        method: Optional[str],
        body: Optional[JsonBody],
        headers: Optional[Mapping[str, str]],
        timeout: Union[float, httpx.Timeout, None],
    ) -> Tuple[str, str, Optional[bytes], httpx.Headers, Any]:
        # Only a plain number is ours to validate — an `httpx.Timeout` is the
        # caller's own object, budgeting connect/read/write/pool separately,
        # and httpx already validates it on its own terms.
        if isinstance(timeout, (int, float)) and timeout <= 0:
            raise ValueError("EchoRelay: timeout must be greater than 0")

        path = endpoint if endpoint.startswith("/") else f"/{endpoint}"
        url = f"{self.base_url}/{line}{path}"

        # httpx.Headers is case-insensitive like fetch's Headers: a caller's
        # own spelling of a header replaces the default instead of appearing
        # beside it — header names are case-insensitive, so two dicts
        # differing only in case must not both survive.
        request_headers = httpx.Headers({"Authorization": f"Bearer {self.api_key}"})
        content: Optional[bytes] = None
        if body is not None:
            request_headers["Content-Type"] = "application/json"
            content = json.dumps(body).encode("utf-8")
        if headers:
            for name, value in headers.items():
                request_headers[name] = value

        # httpx reads `timeout=None` as "no timeout at all", so passing the
        # caller's absent value straight through would silently disable the
        # client's own configured wait. Absent means "whatever the client
        # was built with"; only a value the caller gave narrows it.
        request_timeout = httpx.USE_CLIENT_DEFAULT if timeout is None else timeout

        return url, method if method is not None else "POST", content, request_headers, request_timeout

    @staticmethod
    def response_headers(response: httpx.Response) -> Dict[str, str]:
        return dict(response.headers)

    @staticmethod
    def response_body(response: httpx.Response) -> Any:
        text = response.text
        if not text:
            return None
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            return text

    @staticmethod
    def map_transport_error(exc: httpx.RequestError) -> EchoRelayError:
        return EchoRelayError(str(exc) or "network request failed", 0)

    @staticmethod
    def raise_for_status(response: httpx.Response) -> None:
        if not response.is_success:
            raise error_from_response(response)
