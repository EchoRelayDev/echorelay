/**
 * Thrown for any non-2xx response and for a request that never reached the
 * server (DNS/TCP/TLS failure, abort). `status` is 0 for the latter case.
 *
 * `code` and `requestId` come from the relay's flat error envelope,
 * `{"error":"<code>","requestId":"<uuid>"}` — present on every rejection the
 * relay itself produces, absent when the failure never reached it.
 */
export class EchoRelayError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly requestId?: string;

  constructor(message: string, status: number, code?: string, requestId?: string) {
    super(message);
    this.name = "EchoRelayError";
    this.status = status;
    this.code = code;
    this.requestId = requestId;
  }
}

/**
 * Builds an `EchoRelayError` from a fetch `Response` already known to be
 * non-2xx. Reads the body once as text, then tries the relay's flat JSON
 * envelope; a body that isn't JSON (an intermediary's error page, for
 * example) still produces an error, keyed on status alone.
 */
export async function errorFromResponse(response: Response): Promise<EchoRelayError> {
  const text = await response.text();
  let code: string | undefined;
  let requestId: string | undefined;
  if (text) {
    try {
      const parsed = JSON.parse(text) as { error?: unknown; requestId?: unknown };
      if (typeof parsed.error === "string") code = parsed.error;
      if (typeof parsed.requestId === "string") requestId = parsed.requestId;
    } catch {
      // Not JSON — fall through with status-only information.
    }
  }
  const message = code || text || response.statusText || `HTTP ${response.status}`;
  return new EchoRelayError(message, response.status, code, requestId);
}
