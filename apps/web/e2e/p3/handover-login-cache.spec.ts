import { expect, test } from "@playwright/test";
import { readFileSync } from "node:fs";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { Api } from "../support/api";

// The live run logs in from one runner and POST /login is throttled (10 a minute per address): a fixture account must log in once per
// worker, whoever asks, and again only when the API answers 401 (the token was revoked). A tiny local API counts the logins.
// It rides in the p3-handover project (the name matches); no browser and no real API are involved.

interface Fake {
  server: Server;
  origin: string;
  logins: string[];
  revoked: Set<string>;
  throttle: { left: number; retryAfter: string | null };
}

async function fakeApi(): Promise<Fake> {
  const logins: string[] = [];
  const revoked = new Set<string>();
  let serial = 0;
  const throttle = { left: 0, retryAfter: null as string | null };
  const server = createServer((request, response) => {
    let body = "";
    request.on("data", (chunk) => (body += chunk));
    request.on("end", () => {
      response.setHeader("Content-Type", "application/json");
      if (request.url === "/api/v1/login") {
        if (throttle.left > 0) {
          throttle.left -= 1;
          response.statusCode = 429;
          if (throttle.retryAfter) response.setHeader("Retry-After", throttle.retryAfter);
          response.end(JSON.stringify({ message: "Too Many Attempts." }));
          return;
        }
        const { phone } = JSON.parse(body) as { phone: string };
        logins.push(phone);
        response.end(JSON.stringify({ token: `${phone}-${++serial}`, user: { id: 1, branch_id: 1, name: "x", phone, stations: [] }, permissions: [] }));
        return;
      }
      const token = (request.headers.authorization ?? "").replace("Bearer ", "");
      if (revoked.has(token)) {
        response.statusCode = 401;
        response.end(JSON.stringify({ message_key: "auth.unauthenticated" }));
        return;
      }
      response.end(JSON.stringify({ ok: true, token }));
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  return { server, origin: `http://127.0.0.1:${(server.address() as AddressInfo).port}`, logins, revoked, throttle };
}

test.describe("p3 e2e support: login cache", () => {
  test("an account logs in once however often it is asked for, and each phone has its own login", async () => {
    const fake = await fakeApi();
    try {
      const a = await Api.login("+251900000001", "pw", 1, fake.origin);
      const b = await Api.login("+251900000001", "pw", 1, fake.origin);
      const c = await Api.login("+251900000002", "pw", 1, fake.origin);
      expect(fake.logins).toEqual(["+251900000001", "+251900000002"]);
      expect(a.api.token).toBe(b.api.token);
      expect(c.api.token).not.toBe(a.api.token);
      expect(await a.api.get<{ ok: boolean }>("/me")).toEqual({ ok: true, token: a.api.token });
    } finally {
      fake.server.close();
    }
  });

  test("a 401 logs the account in again once, and the request is retried with the new token", async () => {
    const fake = await fakeApi();
    try {
      const { api } = await Api.login("+251900000003", "pw", undefined, fake.origin);
      const first = api.token!;
      fake.revoked.add(first);
      const answer = await api.get<{ ok: boolean; token: string }>("/me");
      expect(fake.logins).toEqual(["+251900000003", "+251900000003"]);
      expect(answer.ok).toBe(true);
      expect(answer.token).not.toBe(first);
      expect(api.token).toBe(answer.token);
      // the next caller gets the fresh login from the cache, not a third one
      const again = await Api.login("+251900000003", "pw", undefined, fake.origin);
      expect(again.api.token).toBe(answer.token);
      expect(fake.logins).toHaveLength(2);
    } finally {
      fake.server.close();
    }
  });

  test("a 429 on the login waits for Retry-After and tries once more", async () => {
    const fake = await fakeApi();
    fake.throttle.left = 1;
    fake.throttle.retryAfter = "1";
    try {
      const started = Date.now();
      const { api } = await Api.login("+251900000004", "pw", undefined, fake.origin);
      expect(api.token).toBeTruthy();
      expect(Date.now() - started).toBeGreaterThanOrEqual(1000);
      expect(fake.logins).toEqual(["+251900000004"]);
    } finally {
      fake.server.close();
    }
  });

  test("the login is also written to the run's shared file, for a worker that restarts", async () => {
    const fake = await fakeApi();
    try {
      const { api } = await Api.login("+251900000005", "pw", 1, fake.origin);
      const file = process.env.E2E_TOKEN_FILE;
      expect(file).toBeTruthy();
      const stored = JSON.parse(readFileSync(file!, "utf8")) as Record<string, { token: string }>;
      expect(stored[`${fake.origin}|+251900000005|1`]?.token).toBe(api.token);
    } finally {
      fake.server.close();
    }
  });
});
