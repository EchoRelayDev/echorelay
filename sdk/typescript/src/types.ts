/** JSON-serializable request body — anything `JSON.stringify` accepts. */
export type JsonBody = Record<string, unknown> | unknown[] | string | number | boolean | null;

export interface EchoRelayConfig {
  /** The bearer minted for one project's inbound keys (a live- or test-mode key — see `testMode`). */
  apiKey: string;
  /**
   * Your project's data-path origin, e.g. `https://{project}.echorelay.cloud`.
   * There is no default: it is per-project and must be supplied.
   */
  baseUrl: string;
  /**
   * Documents which pool `apiKey` draws from; the relay itself decides
   * live vs. test purely from the key you pass, never from a request flag,
   * so this has no effect on the wire — see the README's "Test mode" note.
   */
  testMode?: boolean;
  /** Overrides the global `fetch`, e.g. for a polyfill or a test double. */
  fetch?: typeof fetch;
}

export interface RequestOptions {
  /** HTTP method for the call. Defaults to `POST`. */
  method?: string;
  /** JSON-serialized as the request body when present. */
  body?: JsonBody;
  /** Extra headers merged in after `Content-Type` and the auth header. */
  headers?: Record<string, string>;
  /**
   * Client-side abort timeout in seconds, covering the wait for headers and
   * for the body. Must be greater than 0 — a non-positive value throws
   * rather than being silently ignored. Bounds only how long this call waits
   * locally — the relay decides a sync target's own wait server-side (the
   * endpoint's configured `syncTimeout`), which this value cannot change.
   */
  timeout?: number;
}

/** The target's forwarded reply, as returned by a `sync`-configured endpoint. */
export interface SyncResult {
  status: number;
  headers: Record<string, string>;
  body: unknown;
}
