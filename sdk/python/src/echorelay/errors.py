"""Error type for the EchoRelay client."""

from __future__ import annotations

import json
from typing import Optional

import httpx


class EchoRelayError(Exception):
    """Raised for any non-2xx response and for a request that never reached the
    server (DNS/TCP/TLS failure, timeout). `status` is 0 for the latter case.

    `code` and `request_id` come from the relay's flat error envelope,
    `{"error": "<code>", "requestId": "<uuid>"}` — present on every rejection
    the relay itself produces, absent when the failure never reached it.
    """

    def __init__(
        self,
        message: str,
        status: int,
        code: Optional[str] = None,
        request_id: Optional[str] = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.code = code
        self.request_id = request_id


def error_from_response(response: httpx.Response) -> EchoRelayError:
    """Build an `EchoRelayError` from an `httpx.Response` already known to be
    non-2xx. Reads the body already buffered on the response, then tries the
    relay's flat JSON envelope; a body that isn't JSON (an intermediary's
    error page, for example) still produces an error, keyed on status alone.
    """
    text = response.text
    code: Optional[str] = None
    request_id: Optional[str] = None
    if text:
        try:
            parsed = json.loads(text)
        except json.JSONDecodeError:
            parsed = None
        if isinstance(parsed, dict):
            error_value = parsed.get("error")
            if isinstance(error_value, str):
                code = error_value
            request_id_value = parsed.get("requestId")
            if isinstance(request_id_value, str):
                request_id = request_id_value

    message = code or text or response.reason_phrase or f"HTTP {response.status_code}"
    return EchoRelayError(message, response.status_code, code, request_id)
