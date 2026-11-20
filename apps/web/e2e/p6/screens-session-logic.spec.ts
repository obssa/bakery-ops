import { expect, test } from "@playwright/test";
import type { LoginResponse, Me } from "../../src/lib/api/types";
import { sessionRetry, signOutTarget, useSession } from "../../src/lib/session";

// The sign-in's races (implicit-state review, 2026-11-20; lib/session.ts): an answer of GET /me counts only for the sign-in it was asked
// for. A slow check of the token stored on the device must not sign out the person who signed in meanwhile, nor put the old person back;
// one that ends after a sign-out must not sign the device in again; a sign-in or a start-up that could not read /me asks again. Plain
// logic with fetch replaced (no browser, no API): it runs in p6-a11y beside the screens, as the other *-logic specs do.

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

function person(id: number, name: string): Me {
  return {
    user: { id, name, branch_id: 1, roles: ["cashier"] } as unknown as Me["user"],
    permissions: ["payments.take"],
    locale: "en",
    acting: null,
    device: null,
    work_stations: [{ id: 2, code: "till" } as unknown as Me["work_stations"][number]],
  };
}

function login(token: string, me: Me): LoginResponse {
  return { token, token_type: "Bearer", user: me.user, permissions: me.permissions, locale: me.locale };
}

/** A /me answer that the test releases when it chooses, so the order of the answers is the test's. */
interface Held {
  token: string;
  release: (response: Response | "network") => void;
}

/** Replaces fetch: each GET /me is held until released (in call order, per token); POST /logout answers 204 at once. */
async function withApi(body: (api: { held: Held[]; meCalls: () => number; waitFor: (n: number) => Promise<void> }) => Promise<void>): Promise<void> {
  const real = globalThis.fetch;
  const held: Held[] = [];
  let calls = 0;
  globalThis.fetch = ((url: RequestInfo | URL, init?: RequestInit) => {
    const path = String(url);
    if (path.endsWith("/logout")) return Promise.resolve(new Response(null, { status: 204 }));
    calls += 1;
    const token = (new Headers(init?.headers).get("Authorization") ?? "").replace(/^Bearer /, "");
    return new Promise<Response>((resolve, reject) => {
      held.push({ token, release: (response) => (response === "network" ? reject(new TypeError("Failed to fetch")) : resolve(response)) });
    });
  }) as typeof fetch;
  const waitFor = async (n: number) => {
    for (let i = 0; i < 200 && held.length < n; i++) await new Promise((r) => setTimeout(r, 5));
    expect(held.length, `GET /me asked ${n} times`).toBeGreaterThanOrEqual(n);
  };
  try {
    await body({ held, meCalls: () => calls, waitFor });
  } finally {
    globalThis.fetch = real;
  }
}

const settle = () => new Promise((r) => setTimeout(r, 20));
const OLD = person(1, "Old person");
const NEW = person(2, "New person");
const DEFAULT_DELAYS = [...sessionRetry.delays];

test.describe("the sign-in's races (src/lib/session.ts)", () => {
  test.beforeEach(() => {
    // a fresh device: expire() starts a new generation (dropping any answer and retry of the test before), then nothing is known yet
    useSession.getState().expire();
    useSession.setState({ token: null, me: null, status: "unknown", expired: false, unreachable: false });
  });
  test.afterEach(() => {
    sessionRetry.delays = [...DEFAULT_DELAYS];
    useSession.getState().expire();
  });

  test("a refusal of the old token that arrives after a sign-in does not sign the new person out", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      const signingIn = useSession.getState().signIn(login("new", NEW));
      await waitFor(2);
      held[1]!.release(json(200, NEW));
      expect((await signingIn).user.name).toBe("New person");
      held[0]!.release(json(401, { message_key: "auth.unauthenticated" }));
      await starting;
      const s = useSession.getState();
      expect(s.status).toBe("signed-in");
      expect(s.token).toBe("new");
      expect(s.me?.user.name).toBe("New person");
    });
  });

  test("the old person's answer that arrives after a sign-in does not put them back", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      const signingIn = useSession.getState().signIn(login("new", NEW));
      await waitFor(2);
      held[1]!.release(json(200, NEW));
      await signingIn;
      held[0]!.release(json(200, OLD));
      await starting;
      expect(useSession.getState().me?.user.name).toBe("New person");
      expect(useSession.getState().token).toBe("new");
    });
  });

  test("a sign-in whose own /me is slower than the old check still ends with the new person", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      const signingIn = useSession.getState().signIn(login("new", NEW));
      await waitFor(2);
      held[0]!.release(json(401, { message_key: "auth.unauthenticated" }));
      await starting;
      // the sign-in answer stands while its own /me is on the way
      expect(useSession.getState().status).toBe("signed-in");
      expect(useSession.getState().token).toBe("new");
      held[1]!.release(json(200, NEW));
      await signingIn;
      expect(useSession.getState().me?.work_stations).toHaveLength(1);
    });
  });

  test("a sign-out while the start-up check is on the way stays signed out (no signed-in device with no token)", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      await useSession.getState().signOut();
      held[0]!.release(json(200, OLD));
      await starting;
      const s = useSession.getState();
      expect(s.status).toBe("signed-out");
      expect(s.token).toBeNull();
      expect(s.me).toBeNull();
    });
  });

  test("an expiry (a 401 elsewhere) while the start-up check is on the way stays signed out", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      useSession.getState().expire();
      held[0]!.release(json(200, OLD));
      await starting;
      expect(useSession.getState().status).toBe("signed-out");
      expect(useSession.getState().expired).toBe(true);
    });
  });

  test("on its own, a refused stored token still signs the device out, and a good one signs in", async () => {
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      held[0]!.release(json(401, { message_key: "auth.unauthenticated" }));
      await starting;
      expect(useSession.getState().status).toBe("signed-out");
      expect(useSession.getState().token).toBeNull();

      useSession.setState({ token: "old", status: "unknown" });
      const again = useSession.getState().init();
      await waitFor(2);
      held[1]!.release(json(200, OLD));
      await again;
      expect(useSession.getState().status).toBe("signed-in");
      expect(useSession.getState().me?.user.name).toBe("Old person");
    });
  });

  test("a start-up that cannot reach /me asks again by itself and then signs in", async () => {
    sessionRetry.delays = [30];
    await withApi(async ({ held, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      held[0]!.release("network");
      await starting;
      expect(useSession.getState().status).toBe("unknown");
      expect(useSession.getState().unreachable).toBe(true);
      expect(useSession.getState().token).toBe("old");

      await waitFor(2);
      held[1]!.release(json(200, OLD));
      await settle();
      expect(useSession.getState().status).toBe("signed-in");
      expect(useSession.getState().unreachable).toBe(false);
    });
  });

  test("a sign-out drops the pending retry", async () => {
    sessionRetry.delays = [30];
    await withApi(async ({ held, meCalls, waitFor }) => {
      useSession.setState({ token: "old" });
      const starting = useSession.getState().init();
      await waitFor(1);
      held[0]!.release("network");
      await starting;
      await useSession.getState().signOut();
      await new Promise((r) => setTimeout(r, 120));
      expect(meCalls()).toBe(1);
      expect(useSession.getState().status).toBe("signed-out");
    });
  });

  test("a sign-in whose /me could not be read keeps the sign-in answer, then reads /me again (work stations, device)", async () => {
    sessionRetry.delays = [30];
    await withApi(async ({ held, waitFor }) => {
      const signingIn = useSession.getState().signIn(login("new", NEW));
      await waitFor(1);
      held[0]!.release("network");
      const first = await signingIn;
      expect(first.work_stations).toEqual([]);
      expect(useSession.getState().status).toBe("signed-in");

      await waitFor(2);
      expect(held[1]!.token).toBe("new");
      held[1]!.release(json(200, NEW));
      await settle();
      expect(useSession.getState().me?.work_stations).toHaveLength(1);
    });
  });

  test("a sign-in whose /me is refused keeps the sign-in answer and does not ask again", async () => {
    sessionRetry.delays = [30];
    await withApi(async ({ held, meCalls, waitFor }) => {
      const signingIn = useSession.getState().signIn(login("new", NEW));
      await waitFor(1);
      held[0]!.release(json(403, { message_key: "auth.forbidden" }));
      await signingIn;
      await new Promise((r) => setTimeout(r, 120));
      expect(meCalls()).toBe(1);
      expect(useSession.getState().status).toBe("signed-in");
    });
  });

  test("a deliberate sign-out comes back to the screen it left; the start page and the sign-in page do not", () => {
    expect(signOutTarget("/till")).toEqual({ pathname: "/login", query: { next: "/till" } });
    expect(signOutTarget("/admin/orders")).toEqual({ pathname: "/login", query: { next: "/admin/orders" } });
    expect(signOutTarget("/")).toEqual({ pathname: "/login" });
    expect(signOutTarget("/login")).toEqual({ pathname: "/login" });
    expect(signOutTarget("//elsewhere.example")).toEqual({ pathname: "/login" });
  });
});
