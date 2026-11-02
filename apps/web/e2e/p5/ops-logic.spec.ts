import { expect, test } from "@playwright/test";
import { SYSTEM_POLL } from "../../src/lib/live/system";
import { backupState, drillState, flagRows, heartbeatState, wholeMb, type SystemStatus } from "../../src/lib/system";

// Phase 5 step 5.5c, the logic under the System page and ops-live.spec.ts (no browser, no stack): how GET /system/status reads. The
// heartbeat is fresh while the last tick is at most 3 minutes old; a good backup is fresh for 26 hours; a drill counts once it ran
// (`skipped` is the drill without its scratch database: "not configured yet"), and is fresh for 8 days.

const NOW = Date.parse("2026-11-20T09:00:00Z");
const hoursAgo = (h: number) => new Date(NOW - h * 3_600_000).toISOString();

function status(over: Partial<SystemStatus["backups"]> = {}): SystemStatus["backups"] {
  return { last_ok_at: hoursAgo(6), last_status: "ok", bytes: 3_145_728, age_h: 6, kept: 2, disk_used_mb: 4, drill: { last_at: null, status: null }, ...over };
}

test.describe("p5 System page logic", () => {
  test("polls every 30 s while the page is visible, conditionally", () => {
    expect(SYSTEM_POLL.intervalMs).toBe(30_000);
    expect(SYSTEM_POLL.hiddenMs).toBeGreaterThanOrEqual(30_000);
  });

  test("the heartbeat is running while the last tick is at most 3 minutes old, stopped after, never seen without one", () => {
    expect(heartbeatState({ last_tick_at: new Date(NOW - 60_000).toISOString(), ok: true }, NOW)).toBe("running");
    expect(heartbeatState({ last_tick_at: new Date(NOW - 180_000).toISOString(), ok: true }, NOW)).toBe("running");
    expect(heartbeatState({ last_tick_at: new Date(NOW - 181_000).toISOString(), ok: true }, NOW)).toBe("stopped");
    expect(heartbeatState({ last_tick_at: new Date(NOW - 60_000).toISOString(), ok: false }, NOW)).toBe("stopped");
    expect(heartbeatState({ last_tick_at: null, ok: false }, NOW)).toBe("never");
  });

  test("a backup is fresh for 26 hours; none yet, a failed last run and a stale one each read their own way", () => {
    expect(backupState(status(), NOW)).toBe("ok");
    expect(backupState(status({ last_ok_at: hoursAgo(25.9) }), NOW)).toBe("ok");
    expect(backupState(status({ last_ok_at: hoursAgo(26.1), age_h: 26 }), NOW)).toBe("stale");
    expect(backupState(status({ last_status: "failed" }), NOW)).toBe("failed");
    expect(backupState(status({ last_status: "running" }), NOW)).toBe("running");
    expect(backupState(status({ last_ok_at: null, last_status: null, age_h: null, bytes: null }), NOW)).toBe("none");
    expect(backupState(status({ last_ok_at: null, last_status: "failed", age_h: null, bytes: null }), NOW)).toBe("failed");
  });

  test("a drill that has not run, or ran without its scratch database (skipped), is not configured yet; an ok one is fresh for 8 days", () => {
    expect(drillState({ last_at: null, status: null }, NOW)).toBe("not_configured");
    expect(drillState({ last_at: hoursAgo(30), status: "skipped" }, NOW)).toBe("not_configured");
    expect(drillState({ last_at: hoursAgo(30), status: "ok" }, NOW)).toBe("ok");
    expect(drillState({ last_at: hoursAgo(8 * 24 - 1), status: "ok" }, NOW)).toBe("ok");
    expect(drillState({ last_at: hoursAgo(8 * 24 + 1), status: "ok" }, NOW)).toBe("old");
    expect(drillState({ last_at: hoursAgo(2), status: "failed" }, NOW)).toBe("failed");
    expect(drillState({ last_at: hoursAgo(1), status: "running" }, NOW)).toBe("running");
  });

  test("lists the rollout flags in a fixed order, the known ones by name and any other as it is", () => {
    const rows = flagRows({ require_shift_for_cash: true, require_enrolled_device: false, open_ad_hoc_on_accept: true, require_shift_for_station_locations: true, later_flag: false });
    expect(rows.map((r) => r.key)).toEqual(["require_enrolled_device", "require_shift_for_cash", "require_shift_for_station_locations", "open_ad_hoc_on_accept", "later_flag"]);
    expect(rows.map((r) => r.known)).toEqual([true, true, true, true, false]);
    expect(rows.map((r) => r.on)).toEqual([false, true, true, true, false]);
  });

  test("writes sizes as whole MiB from integers, never through a float of the bytes", () => {
    expect(wholeMb(null)).toBeNull();
    expect(wholeMb(0)).toBe(0);
    expect(wholeMb(524_287)).toBe(0);
    expect(wholeMb(524_288)).toBe(1);
    expect(wholeMb(3_145_728)).toBe(3);
  });
});
