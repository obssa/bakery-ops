import { randomUUID } from "node:crypto";
import type { Page } from "@playwright/test";
import type { Api } from "./api";
import { admin, live, run } from "./env";
import { STAFF, type StaffRole } from "./staff";

// The devices of the e2e fixtures (Phase 5 step 5.1b; ADR 0017). CI runs the stack with IDENTITY_REQUIRE_DEVICE=true, so every station
// route answers only an enrolled device: each browser context and each fixture API client sends the device of its account's role, and
// global setup enrols those devices through the API, as the admin of their branch, before the first test.
// - CI: one id per role and run, `e2e-<run>-<role>` (the run is fixed in global setup for every worker, E2E_DEVICE_RUN).
// - Live (the deploy's @live suites, enforcement off): stable ids per role, `e2e-live-<role>`, so the E2E Test Branch keeps one device
//   per role instead of growing one per run. A revoked id is never enrolled again (ADR 0017 decision 6): if one was revoked, the next
//   generation (`e2e-live-<role>-2`, …) is enrolled instead.
// Global setup hands the enrolled ids to the workers in E2E_DEVICES. The demo bakery's admin (CI only, never on the live host) gets a
// device in the demo branch, which is also the "another branch" device of the p5-device flow.

export const DEVICE_ROLES = ["admin", "manager", "cashier", "waiter", "chef", "barista", "cake_baker", "bread_baker", "other", "demo_admin"] as const;
export type DeviceRole = (typeof DEVICE_ROLES)[number];

/** Where the app keeps its device id in localStorage (DEVICE_STORAGE_KEY in src/lib/device.ts; not imported, see api.ts). */
const DEVICE_STORAGE_KEY = "bakery-ops.device-id";

/** The run the fixture devices belong to: global setup fixes it for every worker; a spec run without it uses this process's run. */
export function deviceRun(): string {
  return process.env.E2E_DEVICE_RUN || run;
}

/** The fixed id of a role's device: per run in CI, stable on the live host; generation 2 and up after a revoke. */
export function deviceFingerprint(role: DeviceRole, options: { live?: boolean; run?: string; generation?: number } = {}): string {
  const base = (options.live ?? live) ? `e2e-live-${role}` : `e2e-${options.run ?? deviceRun()}-${role}`;
  const generation = options.generation ?? 1;
  return generation > 1 ? `${base}-${generation}` : base;
}

/** The role an account's devices belong to: the fixed staff by phone, the two admins from the environment, anyone else "other". */
export function roleOfPhone(
  phone: string | null | undefined,
  accounts: { admin?: string; demo_admin?: string } = { admin: admin.phone, demo_admin: process.env.DEMO_ADMIN_PHONE },
): DeviceRole {
  if (!phone) return "other";
  if (accounts.admin && phone === accounts.admin) return "admin";
  if (accounts.demo_admin && phone === accounts.demo_admin) return "demo_admin";
  for (const [role, staff] of Object.entries(STAFF)) if (staff.phone === phone) return role as StaffRole;
  return "other";
}

function enrolledIds(): Partial<Record<DeviceRole, string>> {
  try {
    return JSON.parse(process.env.E2E_DEVICES || "{}") as Partial<Record<DeviceRole, string>>;
  } catch {
    return {};
  }
}

/** The device an account's browser contexts and API calls send: its role's, as global setup enrolled it. */
export function deviceOf(phone: string | null | undefined): string {
  const role = roleOfPhone(phone);
  return enrolledIds()[role] ?? deviceFingerprint(role);
}

/** Puts `fingerprint` where the app keeps its device id, before the page's own scripts run, on every navigation of `page`. */
export async function placeDevice(page: Page, fingerprint: string): Promise<void> {
  await page.addInitScript(
    ([key, value]) => {
      try {
        window.localStorage.setItem(key, value);
      } catch {
        // storage blocked: the app makes an id of its own and the station screens say it is not enrolled
      }
    },
    [DEVICE_STORAGE_KEY, fingerprint],
  );
}

function deviceName(role: DeviceRole): string {
  return live ? `E2E ${role}` : `E2E ${role} ${deviceRun()}`;
}

/**
 * Makes sure `role`'s device is enrolled in the branch of `api` (an admin), and returns its id: asks GET /devices/this with that id
 * (enrolled: done), enrols it with POST /devices when it is unknown, and moves to the next generation when it was revoked or is taken
 * by another branch. Each call goes out with the id it asks about, so a revoked id of another role never refuses the admin's call.
 */
export async function ensureDeviceEnrolled(api: Api, role: DeviceRole): Promise<string> {
  for (let generation = 1; generation <= 5; generation++) {
    const fingerprint = deviceFingerprint(role, { generation });
    const probe = api.onDevice(fingerprint);
    const here = await probe.get<{ enrolled: boolean; revoked: boolean }>("/devices/this");
    if (here.enrolled) return fingerprint;
    if (here.revoked) continue;
    try {
      await probe.post("/devices", { name: deviceName(role), fingerprint, kind: "tablet", client_uuid: randomUUID() });
      return fingerprint;
    } catch (error) {
      if ((error as { key?: string }).key === "identity.device.fingerprint_taken") continue;
      throw error;
    }
  }
  throw new Error(`no device id left for the e2e role ${role} after five generations (each was revoked or taken)`);
}
