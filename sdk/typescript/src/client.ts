import { EchoRelayError, errorFromResponse } from "./errors.js";
import type { EchoRelayConfig, RequestOptions, SyncResult } from "./types.js";

/**
 * Thin client for an EchoRelay project's data path
 * (`{baseUrl}/{line}/{endpoint}`).
 *
 * `send` and `sendSync` issue the identical HTTP call — method, headers, and
 * body are the same either way. What comes back (an immediate 202, or the
 * target's own forwarded response) is decided by how the endpoint's target
 * is configured server-side, not by which method you call; `sendSync`
 * additionally parses and returns that response instead of discarding it.
 *
 * The API key travels as `Authorization: Bearer {apiKey}` on every call.
 */
export class EchoRelay {
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly fetchImpl: typeof fetch;
  /** Stored as given; carries no wire effect — see `EchoRelayConfig.testMode`. */
  readonly testMode: boolean;

  constructor(config: EchoRelayConfig) {
    if (!config.apiKey) throw new Error("EchoRelay: apiKey is required");
    if (!config.baseUrl) throw new Error("EchoRelay: baseUrl is required");
    this.apiKey = config.apiKey;
    this.baseUrl = config.baseUrl.replace(/\/+$/, "");
    this.testMode = config.testMode ?? false;
    this.fetchImpl = config.fetch ?? fetch;
  }

  /** Fire a request; resolves once the relay accepts it, discarding the response body. */
  async send(line: string, endpoint: string, options: RequestOptions = {}): Promise<void> {
    await this.dispatch(line, endpoint, options, async (response) => {
      // An undrained body holds its connection open until the runtime collects it.
      await response.body?.cancel();
    });
  }

  /** Fire a request and return the forwarded target response: status, headers, and parsed body. */
  async sendSync(line: string, endpoint: string, options: RequestOptions = {}): Promise<SyncResult> {
    return this.dispatch(line, endpoint, options, async (response) => {
      const headers: Record<string, string> = {};
      response.headers.forEach((value, key) => {
        headers[key] = value;
      });
      const text = await response.text();
      let body: unknown = undefined;
      if (text) {
        try {
          body = JSON.parse(text);
        } catch {
          body = text;
        }
      }
      return { status: response.status, headers, body };
    });
  }

  /**
   * Builds and fires the request, then hands the response to `consume` —
   * `send` drains it, `sendSync` parses it — before the deadline is lifted.
   * The abort timer used to clear as soon as `fetch` resolved with a
   * `Response`, which is only once headers arrive; a target that stalled
   * while streaming the body back was then unbounded, because nothing was
   * left to fire the abort `sendSync`'s later `response.text()` needed. It
   * now stays armed until `consume` (and, for a non-2xx response, reading the
   * relay's error envelope) has finished, so a stall at any point during the
   * call — not just before headers — is bounded and reported the same way:
   * an `EchoRelayError` with `status: 0`.
   */
  private async dispatch<T>(
    line: string,
    endpoint: string,
    options: RequestOptions,
    consume: (response: Response) => Promise<T>,
  ): Promise<T> {
    const path = endpoint.startsWith("/") ? endpoint : `/${endpoint}`;
    const url = `${this.baseUrl}/${line}${path}`;

    // `Headers` so a caller's own spelling of a header replaces the default
    // instead of being appended beside it: HTTP header names are
    // case-insensitive, and two records differing only in case combine.
    const headers = new Headers({ Authorization: `Bearer ${this.apiKey}` });
    let body: string | undefined;
    if (options.body !== undefined) {
      headers.set("Content-Type", "application/json");
      body = JSON.stringify(options.body);
    }
    for (const [name, value] of Object.entries(options.headers ?? {})) headers.set(name, value);

    let controller: AbortController | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    if (options.timeout !== undefined) {
      if (options.timeout <= 0) throw new Error("EchoRelay: timeout must be greater than 0");
      controller = new AbortController();
      timer = setTimeout(() => controller!.abort(), options.timeout * 1000);
    }

    try {
      const response = await this.fetchImpl(url, {
        method: options.method ?? "POST",
        headers,
        body,
        // A sync target's own redirect is forwarded verbatim; following it would
        // send this request's credentials to a host the relay never named.
        redirect: "manual",
        signal: controller?.signal,
      });

      if (!response.ok) {
        throw await errorFromResponse(response);
      }
      return await consume(response);
    } catch (err) {
      if (err instanceof EchoRelayError) throw err;
      const message = err instanceof Error ? err.message : "network request failed";
      throw new EchoRelayError(message, 0);
    } finally {
      if (timer) clearTimeout(timer);
    }
  }
}
