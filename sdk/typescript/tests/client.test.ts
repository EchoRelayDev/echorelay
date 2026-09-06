import { describe, expect, it, vi } from "vitest";
import { EchoRelay, EchoRelayError } from "../src/index.js";

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json", ...headers },
  });
}

describe("EchoRelay", () => {
  it("requires apiKey and baseUrl", () => {
    expect(() => new EchoRelay({ apiKey: "", baseUrl: "https://t.echorelay.cloud" })).toThrow();
    expect(() => new EchoRelay({ apiKey: "k", baseUrl: "" })).toThrow();
  });

  it("send() posts JSON with the auth header and resolves on 202", async () => {
    const fetchMock = vi.fn(async (url: string, init: RequestInit) => {
      expect(url).toBe("https://t.echorelay.cloud/v1/notify");
      expect(init.method).toBe("POST");
      const headers = new Headers(init.headers);
      expect(headers.get("authorization")).toBe("Bearer secret-key");
      expect(headers.get("content-type")).toBe("application/json");
      expect(init.body).toBe(JSON.stringify({ hello: "world" }));
      return jsonResponse(202, { status: "queued" });
    });

    const relay = new EchoRelay({
      apiKey: "secret-key",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    await expect(relay.send("v1", "/notify", { body: { hello: "world" } })).resolves.toBeUndefined();
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  it("sendSync() returns the forwarded target's status, headers, and parsed body", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, { enriched: true }, { "x-target-trace": "abc123" })
    );

    const relay = new EchoRelay({
      apiKey: "secret-key",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const result = await relay.sendSync("v1", "/enrich", { body: { name: "ana" } });
    expect(result.status).toBe(200);
    expect(result.body).toEqual({ enriched: true });
    expect(result.headers["x-target-trace"]).toBe("abc123");
  });

  it("sendSync() returns a non-JSON body as text", async () => {
    const fetchMock = vi.fn(
      async () => new Response("plain text reply", { status: 200, headers: { "content-type": "text/plain" } })
    );
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const result = await relay.sendSync("v1", "/enrich", {});
    expect(result.body).toBe("plain text reply");
  });

  it("maps a 401 to a typed EchoRelayError with status, code, and requestId", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(401, { error: "key_revoked", requestId: "11111111-1111-1111-1111-111111111111" })
    );
    const relay = new EchoRelay({
      apiKey: "revoked-key",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const error = await relay.send("v1", "/notify", { body: {} }).catch((e) => e);
    expect(error).toBeInstanceOf(EchoRelayError);
    expect((error as EchoRelayError).status).toBe(401);
    expect((error as EchoRelayError).code).toBe("key_revoked");
    expect((error as EchoRelayError).requestId).toBe("11111111-1111-1111-1111-111111111111");
  });

  it("maps a 500 with a flat error envelope to a typed EchoRelayError", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(500, { error: "internal_error", requestId: "req-2" })
    );
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    await expect(relay.sendSync("v1", "/enrich", {})).rejects.toMatchObject({
      status: 500,
      code: "internal_error",
      requestId: "req-2",
    });
  });

  it("maps a fetch-level failure (no response) to an EchoRelayError with status 0", async () => {
    const fetchMock = vi.fn(async () => {
      throw new TypeError("fetch failed");
    });
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const error = await relay.send("v1", "/notify", {}).catch((e) => e);
    expect(error).toBeInstanceOf(EchoRelayError);
    expect((error as EchoRelayError).status).toBe(0);
    expect((error as EchoRelayError).message).toBe("fetch failed");
  });

  it("omits Content-Type when no body is given", async () => {
    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      const headers = new Headers(init.headers);
      expect(headers.get("content-type")).toBeNull();
      expect(init.body).toBeUndefined();
      return jsonResponse(202, { status: "queued" });
    });
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    await relay.send("v1", "/ping", {});
  });

  it("lets a caller's own headers win over the defaults", async () => {
    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      const headers = new Headers(init.headers);
      expect(headers.get("content-type")).toBe("application/vnd.api+json");
      expect(headers.get("x-trace")).toBe("t-1");
      return jsonResponse(202, { status: "queued" });
    });
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    await relay.send("v1", "/notify", {
      body: { hello: "world" },
      headers: { "content-type": "application/vnd.api+json", "X-Trace": "t-1" },
    });
  });

  it("carries a message for an error response with an empty body", async () => {
    const fetchMock = vi.fn(
      async () => new Response(null, { status: 503, statusText: "Service Unavailable" })
    );
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const error = (await relay.send("v1", "/notify", {}).catch((e) => e)) as EchoRelayError;
    expect(error.status).toBe(503);
    expect(error.message).toMatch(/Service Unavailable|HTTP 503/);
  });

  it("refuses a non-positive timeout instead of ignoring it", async () => {
    const fetchMock = vi.fn();
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    await expect(relay.send("v1", "/notify", { timeout: 0 })).rejects.toThrow(/greater than 0/);
    await expect(relay.send("v1", "/notify", { timeout: -1 })).rejects.toThrow(/greater than 0/);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("keeps the deadline armed through body consumption, not just until headers arrive", async () => {
    let bodyController: ReadableStreamDefaultController<Uint8Array>;
    const stream = new ReadableStream<Uint8Array>({
      start(controller) {
        bodyController = controller;
      },
    });

    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      // A real fetch tears the body stream down when the request aborts;
      // this stand-in never closes the stream on its own, so the only way
      // `sendSync` below can settle is if the abort actually fires.
      init.signal?.addEventListener("abort", () => {
        bodyController.error(new DOMException("The operation was aborted.", "AbortError"));
      });
      return new Response(stream, { status: 200, headers: { "content-type": "application/json" } });
    });

    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const result = await Promise.race([
      relay.sendSync("v1", "/enrich", { timeout: 0.05 }).catch((e) => e),
      new Promise((_, reject) =>
        setTimeout(() => reject(new Error("sendSync did not honor the timeout during body consumption")), 1000)
      ),
    ]);

    expect(result).toBeInstanceOf(EchoRelayError);
    expect((result as EchoRelayError).status).toBe(0);
  });

  it("surfaces a forwarded redirect instead of following it", async () => {
    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      expect(init.redirect).toBe("manual");
      return new Response(null, { status: 302, headers: { location: "https://elsewhere.test/x" } });
    });
    const relay = new EchoRelay({
      apiKey: "k",
      baseUrl: "https://t.echorelay.cloud",
      fetch: fetchMock as unknown as typeof fetch,
    });

    const error = (await relay.sendSync("v1", "/enrich", {}).catch((e) => e)) as EchoRelayError;
    expect(error).toBeInstanceOf(EchoRelayError);
    expect(error.status).toBe(302);
    expect(fetchMock).toHaveBeenCalledOnce();
  });
});
