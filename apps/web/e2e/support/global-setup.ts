import { randomUUID } from "node:crypto";
import { rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { Api } from "./api";
import { DEVICE_ROLES, deviceRun, ensureDeviceEnrolled, type DeviceRole } from "./devices";
import { admin, live, run } from "./env";

// Runs once per Playwright run, before the first worker. It names the file the API client keeps the run's logins in (see api.ts): a
// worker restarted by a failed test finds its accounts already logged in instead of spending the server's ten logins a minute again.
// The file lives in the system temp folder, not in test-results, so a failed run never uploads session tokens as an artifact.
//
// Step 5.1b: it then enrols the device of every fixture role (devices.ts) as the admin of the E2E Test Branch, and the demo bakery's
// admin's device in the demo branch (CI only; the live demo branch is never touched), and hands the ids to the workers in E2E_DEVICES.
// CI runs the stack with device enforcement on, so a failure there stops the run; the live host runs with it off, so there (and on a
// developer's machine) a failure is only reported and the tests go on.
export default async function globalSetup(): Promise<() => void> {
  const file = join(tmpdir(), `bakery-e2e-sessions-${randomUUID()}.json`);
  process.env.E2E_TOKEN_FILE = file;
  process.env.E2E_DEVICE_RUN ||= run;
  try {
    await enrolFixtureDevices();
  } catch (error) {
    if (process.env.CI && !live) throw error;
    console.warn(`[devices] the fixture devices could not be enrolled: ${error instanceof Error ? error.message : String(error)}`);
  }
  return () => rmSync(file, { force: true });
}

async function enrolFixtureDevices(): Promise<void> {
  if (!admin.phone || !admin.password) {
    console.log("[devices] E2E_ADMIN_PHONE / E2E_ADMIN_PASSWORD are not set: no fixture device is enrolled");
    return;
  }
  const ids: Partial<Record<DeviceRole, string>> = {};
  const { api } = await Api.login(admin.phone, admin.password);
  for (const role of DEVICE_ROLES) {
    if (role !== "demo_admin") ids[role] = await ensureDeviceEnrolled(api, role);
  }
  const demoPhone = process.env.DEMO_ADMIN_PHONE;
  const demoPassword = process.env.DEMO_ADMIN_PASSWORD;
  if (!live && demoPhone && demoPassword) {
    const { api: demo } = await Api.login(demoPhone, demoPassword);
    ids.demo_admin = await ensureDeviceEnrolled(demo, "demo_admin");
  }
  process.env.E2E_DEVICES = JSON.stringify(ids);
  console.log(`[devices] ${Object.keys(ids).length} fixture devices enrolled (${live ? "the stable live ids" : `run ${deviceRun()}`})`);
}
