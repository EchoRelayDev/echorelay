"""Thin client for an EchoRelay project's data path (`{base_url}/{line}/{endpoint}`).

`send` and `send_sync` issue the identical HTTP call — method, headers, and
body are the same either way. What comes back (an immediate 202, or the
target's own forwarded response) is decided by how the endpoint's target is
configured server-side, not by which method you call; `send_sync`
additionally parses and returns that response instead of discarding it.

The API key travels as `Authorization: Bearer {api_key}` on every call.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Mapping, Optional, Union

import httpx

from ._request import JsonBody, _RequestBuilder

__all__ = ["EchoRelay", "AsyncEchoRelay", "SyncResult", "JsonBody"]


@dataclass(frozen=True)
class SyncResult:
    """The target's forwarded reply, as returned by a `sync`-configured endpoint."""

    status: int
    headers: Dict[str, str]
    body: Any


class EchoRelay:
    """Synchronous EchoRelay client."""

    def __init__(
        self,
        api_key: str,
        base_url: str,
        test_mode: bool = False,
        http_client: Optional[httpx.Client] = None,
    ) -> None:
        self._builder = _RequestBuilder(api_key, base_url, test_mode)
        self._client = http_client or httpx.Client()
        self._owns_client = http_client is None

    @property
    def test_mode(self) -> bool:
        return self._builder.test_mode

    def send(
        self,
        line: str,
        endpoint: str,
        *,
        method: Optional[str] = None,
        body: Optional[JsonBody] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Union[float, httpx.Timeout, None] = None,
    ) -> None:
        """Fire a request; returns once the relay accepts it, discarding the response body."""
        self._dispatch(line, endpoint, method, body, headers, timeout)

    def send_sync(
        self,
        line: str,
        endpoint: str,
        *,
        method: Optional[str] = None,
        body: Optional[JsonBody] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Union[float, httpx.Timeout, None] = None,
    ) -> SyncResult:
        """Fire a request and return the forwarded target response: status, headers, and parsed body."""
        response = self._dispatch(line, endpoint, method, body, headers, timeout)
        return SyncResult(
            response.status_code, self._builder.response_headers(response), self._builder.response_body(response)
        )

    def _dispatch(
        self,
        line: str,
        endpoint: str,
        method: Optional[str],
        body: Optional[JsonBody],
        headers: Optional[Mapping[str, str]],
        timeout: Union[float, httpx.Timeout, None],
    ) -> httpx.Response:
        url, http_method, content, request_headers, request_timeout = self._builder.prepare(
            line, endpoint, method, body, headers, timeout
        )
        try:
            response = self._client.request(
                http_method,
                url,
                content=content,
                headers=request_headers,
                # A sync target's own redirect is forwarded verbatim; following
                # it would send this request's credentials to a host the relay
                # never named. Asserted explicitly rather than relied on as
                # httpx's default, since an injected `http_client` could
                # override it.
                follow_redirects=False,
                timeout=request_timeout,
            )
        except httpx.RequestError as exc:
            raise self._builder.map_transport_error(exc) from exc

        self._builder.raise_for_status(response)
        return response

    def close(self) -> None:
        if self._owns_client:
            self._client.close()

    def __enter__(self) -> "EchoRelay":
        return self

    def __exit__(self, *exc_info: object) -> None:
        self.close()


class AsyncEchoRelay:
    """Asynchronous EchoRelay client."""

    def __init__(
        self,
        api_key: str,
        base_url: str,
        test_mode: bool = False,
        http_client: Optional[httpx.AsyncClient] = None,
    ) -> None:
        self._builder = _RequestBuilder(api_key, base_url, test_mode)
        self._client = http_client or httpx.AsyncClient()
        self._owns_client = http_client is None

    @property
    def test_mode(self) -> bool:
        return self._builder.test_mode

    async def send(
        self,
        line: str,
        endpoint: str,
        *,
        method: Optional[str] = None,
        body: Optional[JsonBody] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Union[float, httpx.Timeout, None] = None,
    ) -> None:
        """Fire a request; returns once the relay accepts it, discarding the response body."""
        await self._dispatch(line, endpoint, method, body, headers, timeout)

    async def send_sync(
        self,
        line: str,
        endpoint: str,
        *,
        method: Optional[str] = None,
        body: Optional[JsonBody] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Union[float, httpx.Timeout, None] = None,
    ) -> SyncResult:
        """Fire a request and return the forwarded target response: status, headers, and parsed body."""
        response = await self._dispatch(line, endpoint, method, body, headers, timeout)
        return SyncResult(
            response.status_code, self._builder.response_headers(response), self._builder.response_body(response)
        )

    async def _dispatch(
        self,
        line: str,
        endpoint: str,
        method: Optional[str],
        body: Optional[JsonBody],
        headers: Optional[Mapping[str, str]],
        timeout: Union[float, httpx.Timeout, None],
    ) -> httpx.Response:
        url, http_method, content, request_headers, request_timeout = self._builder.prepare(
            line, endpoint, method, body, headers, timeout
        )
        try:
            response = await self._client.request(
                http_method,
                url,
                content=content,
                headers=request_headers,
                follow_redirects=False,
                timeout=request_timeout,
            )
        except httpx.RequestError as exc:
            raise self._builder.map_transport_error(exc) from exc

        self._builder.raise_for_status(response)
        return response

    async def aclose(self) -> None:
        if self._owns_client:
            await self._client.aclose()

    async def __aenter__(self) -> "AsyncEchoRelay":
        return self

    async def __aexit__(self, *exc_info: object) -> None:
        await self.aclose()
