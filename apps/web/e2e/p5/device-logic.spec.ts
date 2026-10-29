import { expect, test } from "@playwright/test";
import { request } from "../../src/lib/api/client";
import { DEVICE_HEADER, deviceId, isDeviceRefusal, renewDeviceId, shortDeviceId, useDeviceGate } from "../../src/lib/device";
import { Api } from "../support/api";
import { DEVICE_ROLES, deviceFingerprint, freshDevice, roleOfPhone } from "../support/devices";

// Phase 5 step 5.1b, the logic under the p5-device flow (no browser, no stack): the device id every request of the shell carries, what a
// device refusal does to it, and the fixed ids the e2e fixtures enrol per role.

const answer = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

/** Runs `body` with fetch replaced; `respond` sees each call's headers (and may change where the "page" is). */
async function withFetch(respond: (headers: Headers, call: number) => Response, body: () => Promise<void>): Promise<void> {
  const real = globalThis.fetch;
  let call = 0;
  globalThis.fetch = (async (_url: RequestInfo | URL, init?: RequestInit) => respond(new Headers(init?.headers), ++call)) as typeof fetch;
  try {
    await body();
  } finally {
    globalThis.fetch = real;
  }
}

/** Puts a fake `window` with a location (Node has none) for the length of `body`, so the gate can tell which page a call was for. */
async function onPage(pathname: string, body: (location: { pathname: string }) => Promise<void>): Promise<void> {
  const holder = globalThis as unknown as { window?: unknown };
  const before = holder.window;
  const location = { pathname };
  holder.window = { location };
  try {
    await body(location);
  } finally {
    holder.window = before;
  }
}

test.describe("device id of the shell (src/lib/device.ts)", () => {
  test.beforeEach(() => useDeviceGate.getState().clear());

  test("one id per device, kept until it is renewed; the short form is what a person reads", () => {
    const id = deviceId();
    expect(id).toMatch(/^dev-[0-9a-f-]{36}$/);
    expect(deviceId()).toBe(id);
    const renewed = renewDeviceId();
    expect(renewed).not.toBe(id);
    expect(deviceId()).toBe(renewed);
    expect(renewed.length).toBeLessThanOrEqual(191);

    expect(shortDeviceId("dev-1b2c3d4e-0000-4000-8000-a1b2c3d4e5f6")).toBe("C3D4E5F6");
    expect(shortDeviceId("e2e-k3x9-tablet")).toBe("9-TABLET");
    expect(shortDeviceId("short")).toBe("SHORT");
  });

  test("only the three device refusals count", () => {
    for (const key of ["identity.device.unknown", "identity.device.revoked", "identity.device.other_branch"]) {
      expect(isDeviceRefusal({ status: 403, key })).toBe(true);
    }
    expect(isDeviceRefusal({ status: 403, key: "auth.forbidden" })).toBe(false);
    expect(isDeviceRefusal({ status: 422, key: "identity.device.fingerprint_taken" })).toBe(false);
    expect(isDeviceRefusal(null)).toBe(false);
  });

  test("every request carries the device id, after any header the caller passed", async () => {
    const seen: (string | null)[] = [];
    await withFetch(
      (headers) => {
        seen.push(headers.get(DEVICE_HEADER));
        return answer(200, { ok: true });
      },
      async () => {
        await request("/me", { token: "t" });
        await request("/orders", { method: "POST", body: {}, token: "t", idempotencyKey: "k", headers: { [DEVICE_HEADER]: "stale-id" } });
      },
    );
    expect(seen).toEqual([deviceId(), deviceId()]);
  });

  test("an unknown device keeps its id and the gate shows on the page that asked", async () => {
    const id = deviceId();
    await onPage("/app/en/pad/", async () => {
      await withFetch(
        () => answer(403, { message_key: "identity.device.unknown", message_en: "This tablet is not enrolled.", message_am: "አልተመዘገበም።" }),
        async () => {
          await expect(request("/menu", { token: "t" })).rejects.toMatchObject({ status: 403, key: "identity.device.unknown" });
        },
      );
    });
    expect(deviceId()).toBe(id);
    expect(useDeviceGate.getState().refusal).toMatchObject({ key: "identity.device.unknown", messages: { en: "This tablet is not enrolled." } });
  });

  test("a revoked device drops its id and makes a new one, once however many calls come back revoked", async () => {
    const id = deviceId();
    await onPage("/app/en/till/", async () => {
      await withFetch(
        () => answer(403, { message_key: "identity.device.revoked", message_en: "revoked", message_am: "ተሰርዟል" }),
        async () => {
          const both = await Promise.allSettled([request("/orders", { token: "t" }), request("/menu", { token: "t" })]);
          expect(both.map((r) => r.status)).toEqual(["rejected", "rejected"]);
        },
      );
    });
    const renewed = deviceId();
    expect(renewed).not.toBe(id);
    expect(useDeviceGate.getState().refusal?.key).toBe("identity.device.revoked");

    // the next call carries the new id
    let sent: string | null = null;
    await withFetch(
      (headers) => {
        sent = headers.get(DEVICE_HEADER);
        return answer(200, {});
      },
      () => request("/me", { token: "t" }).then(() => undefined),
    );
    expect(sent).toBe(renewed);
  });

  test("a refusal that comes back after the person left that page does not cover the new one", async () => {
    await onPage("/app/en/pad/", async (location) => {
      await withFetch(
        () => {
          location.pathname = "/app/en/admin/catalog/";
          return answer(403, { message_key: "identity.device.unknown", message_en: "x", message_am: "x" });
        },
        async () => {
          await expect(request("/menu", { token: "t" })).rejects.toMatchObject({ status: 403 });
        },
      );
    });
    expect(useDeviceGate.getState().refusal).toBeNull();
  });
});

test.describe("fixture devices (e2e/support/devices.ts)", () => {
  test("a fixed id per role and run; stable ids on the live host, with a next generation when one was revoked", () => {
    expect(deviceFingerprint("waiter", { live: false, run: "r1" })).toBe("e2e-r1-waiter");
    expect(deviceFingerprint("waiter", { live: true, run: "r1" })).toBe("e2e-live-waiter");
    expect(deviceFingerprint("admin", { live: true, run: "r1", generation: 2 })).toBe("e2e-live-admin-2");
    expect(deviceFingerprint("admin", { live: false, run: "r1", generation: 1 })).toBe("e2e-r1-admin");
    for (const role of DEVICE_ROLES) expect(deviceFingerprint(role, { live: false, run: "zzzzzzzzzz" }).length).toBeLessThanOrEqual(191);
  });

  test("an account's role comes from its phone: the fixed staff, the admins, everyone else", () => {
    const accounts = { admin: "+251911000099", demo_admin: "+251900000101" };
    expect(roleOfPhone("+251911000201", accounts)).toBe("chef");
    expect(roleOfPhone("+251911000203", accounts)).toBe("waiter");
    expect(roleOfPhone("+251911000207", accounts)).toBe("bread_baker");
    expect(roleOfPhone("+251911000099", accounts)).toBe("admin");
    expect(roleOfPhone("+251900000101", accounts)).toBe("demo_admin");
    expect(roleOfPhone("+251900000555", accounts)).toBe("other");
    expect(roleOfPhone(undefined, accounts)).toBe("other");
  });

  test("the fixtures' API client sends its device on every request, and onDevice() sends another", async () => {
    const seen: (string | null)[] = [];
    await withFetch(
      (headers) => {
        seen.push(headers.get(DEVICE_HEADER));
        return answer(200, { ok: true });
      },
      async () => {
        const api = new Api("token", "http://api.test", null, "e2e-r1-admin");
        await api.get("/me");
        await api.post("/things", {});
        await api.onDevice("e2e-r1-tablet").get("/devices/this");
        expect(api.device).toBe("e2e-r1-admin");
      },
    );
    expect(seen).toEqual(["e2e-r1-admin", "e2e-r1-admin", "e2e-r1-tablet"]);
  });

  test("a flow's own device is new each time, and enrolled only where the API enforces devices", async () => {
    for (const enforcement of [true, false]) {
      const calls: string[] = [];
      let id = "";
      await withFetch(
        (headers, call) => {
          calls.push(`${call}:${headers.get(DEVICE_HEADER)}:${headers.get("Idempotency-Key") ? "write" : "read"}`);
          return answer(call === 1 ? 200 : 201, call === 1 ? { enrolled: false, revoked: false, device: null, enforcement } : { id: 1 });
        },
        async () => {
          id = await freshDevice(new Api("token", "http://api.test", null, "e2e-r1-admin"), "lockout");
        },
      );
      expect(id).toMatch(/^e2e-.+-lockout-[0-9a-f]{8}$/);
      expect(calls).toEqual(enforcement ? [`1:${id}:read`, `2:${id}:write`] : [`1:${id}:read`]);
    }
  });
});
