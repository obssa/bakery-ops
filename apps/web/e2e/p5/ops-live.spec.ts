import { expect, test } from "@playwright/test";
import { backupState, drillState, heartbeatState, type SystemStatus } from "../../src/lib/system";
import { live, requireAdmin } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";

// Phase 5 step 5.5c (docs/phases/phase-5-plan.md section 6 and 10.3 `live-ops`): the deploy runs this @live spec as the E2E admin after
// every release, and CI's e2e-p5 runs it on the compose stack.
//   - The System page renders for the admin, polled from GET /system/status.
//   - Live only: the scheduler ticked in the last 3 minutes (the cron line of schedule:run writes ops.scheduler.tick every minute). The
//     CI stack runs no scheduler, so there the heartbeat row is only read.
//   - Once backups have run: the last good backup is younger than 26 hours. Once the drill has run: it is younger than 8 days and `ok`.
//     Until then (no backup yet; a drill not run, or `skipped` because the scratch database does not exist yet, plan 10.2 Q8) the spec
//     reports "not configured yet" as an annotation and does not fail; the first real run makes it enforce.
// Read-only: it writes nothing on the live host.

const NOT_CONFIGURED = "not configured yet";

test.describe("p5 ops: the System page", () => {
  test("the System page renders, the scheduler ticks, and the backups and the drill are fresh once they have run @live", async ({ page }) => {
    test.setTimeout(90_000);
    const credentials = requireAdmin(test.skip);
    const { api } = await loginOnce(credentials.phone, credentials.password);
    const status = await api.get<SystemStatus>("/system/status");
    const now = Date.now();

    await openAs(page, api, "en/admin/system/");
    await expect(page.getByRole("heading", { name: "System", exact: true })).toBeVisible({ timeout: 30_000 });
    const scheduler = page.getByTestId("system-scheduler");
    await expect(scheduler).toBeVisible();
    await expect(page.getByTestId("system-backups")).toBeVisible();
    await expect(page.getByTestId("system-queue")).toBeVisible();
    await expect(page.getByTestId("system-devices")).toBeVisible();
    await expect(page.getByTestId("system-flags")).toBeVisible();
    await expect(page.getByRole("link", { name: "System", exact: true })).toHaveAttribute("aria-current", "page");

    if (live) {
      expect(heartbeatState(status.scheduler, now), `the scheduler last ticked at ${status.scheduler.last_tick_at}`).toBe("running");
      await expect(scheduler).toContainText("Running");
    }

    const backups = backupState(status.backups, now);
    if (backups === "none") {
      test.info().annotations.push({ type: NOT_CONFIGURED, description: "no backup has run on this host yet" });
      await expect(page.getByTestId("system-backups")).toContainText("No backup yet");
    } else {
      expect(status.backups.last_ok_at, "a backup has run but none ended ok").not.toBeNull();
      expect(now - Date.parse(status.backups.last_ok_at ?? ""), "the last good backup is younger than 26 hours").toBeLessThan(26 * 3_600_000);
    }

    const drill = drillState(status.backups.drill, now);
    if (drill === "not_configured") {
      test.info().annotations.push({ type: NOT_CONFIGURED, description: `the restore drill has not run (status ${status.backups.drill.status ?? "none"})` });
      await expect(page.getByTestId("system-drill")).toContainText("Not configured yet");
    } else {
      expect(status.backups.drill.status, "the last restore drill").toBe("ok");
      expect(drill, "the last restore drill is younger than 8 days").toBe("ok");
    }
  });
});
