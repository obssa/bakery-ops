// The System page's reading of GET /system/status (phase-5 plan 4.4, step 5.5c; system.view, the admin). Pure: the page, ops-logic.spec.ts and
// the live check (ops-live.spec.ts) read the same answer the same way.
//   - The scheduler is "running" while its last tick (ops.scheduler.tick, written every minute) is at most 3 minutes old and the API says ok;
//     "stopped" after that; "never" when no tick is in the cache.
//   - A good backup is fresh for 26 hours (backup.stale_hours, the age the hourly BackupStale check uses).
//   - A drill counts once it has run: none yet, or `skipped` (BACKUP_DRILL_DATABASE is not set: the scratch database does not exist yet, plan
//     10.2 Q8) reads "not configured yet"; an `ok` drill is fresh for 8 days (it runs every Sunday).
// Sizes are whole MiB: the API sends them so, and wholeMb rounds a byte count the same way (integers only).

export type RunStatus = "running" | "ok" | "failed" | "skipped";

export interface SystemStatus {
  app: { commit: string | null; released_at: string | null; migrations: number };
  scheduler: { last_tick_at: string | null; ok: boolean };
  queue: { pending: number; oldest_age_s: number | null; failed_24h: number; failed_total: number };
  backups: {
    last_ok_at: string | null;
    last_status: RunStatus | null;
    bytes: number | null;
    age_h: number | null;
    kept: number;
    disk_used_mb: number;
    drill: { last_at: string | null; status: RunStatus | null };
  };
  files: { used_mb: number };
  devices: { enrolled: number; seen_15m: number; revoked: number };
  flags: Record<string, boolean>;
  error_tracking: { enabled: boolean };
}

export const HEARTBEAT_MAX_MS = 3 * 60_000;
export const BACKUP_MAX_AGE_MS = 26 * 3_600_000;
export const DRILL_MAX_AGE_MS = 8 * 24 * 3_600_000;

export type HeartbeatState = "running" | "stopped" | "never";
export type BackupState = "ok" | "stale" | "failed" | "running" | "none";
export type DrillState = "ok" | "old" | "failed" | "running" | "not_configured";

function ageMs(iso: string | null, now: number): number | null {
  if (!iso) return null;
  const at = Date.parse(iso);
  return Number.isNaN(at) ? null : now - at;
}

export function heartbeatState(scheduler: SystemStatus["scheduler"], now: number): HeartbeatState {
  const age = ageMs(scheduler.last_tick_at, now);
  if (age === null) return "never";
  return scheduler.ok && age <= HEARTBEAT_MAX_MS ? "running" : "stopped";
}

export function backupState(backups: SystemStatus["backups"], now: number): BackupState {
  if (backups.last_status === "running") return "running";
  if (backups.last_status === "failed") return "failed";
  const age = ageMs(backups.last_ok_at, now);
  if (age === null) return backups.last_status === null ? "none" : "failed";
  return age > BACKUP_MAX_AGE_MS ? "stale" : "ok";
}

export function drillState(drill: SystemStatus["backups"]["drill"], now: number): DrillState {
  if (drill.status === null || drill.status === "skipped") return "not_configured";
  if (drill.status !== "ok") return drill.status;
  const age = ageMs(drill.last_at, now);
  return age !== null && age <= DRILL_MAX_AGE_MS ? "ok" : "old";
}

/** The rollout flags the page names, in the order it lists them; a flag the API adds later is listed after them, by its key. */
export const KNOWN_FLAGS = ["require_enrolled_device", "require_shift_for_cash", "require_shift_for_station_locations", "open_ad_hoc_on_accept"] as const;

export type KnownFlag = (typeof KNOWN_FLAGS)[number];

export function flagRows(flags: Record<string, boolean>): { key: string; known: boolean; on: boolean }[] {
  const known: readonly string[] = KNOWN_FLAGS;
  const rest = Object.keys(flags)
    .filter((key) => !known.includes(key))
    .sort();
  return [...KNOWN_FLAGS.filter((key) => key in flags), ...rest].map((key) => ({ key, known: known.includes(key), on: flags[key] === true }));
}

const MIB = 1_048_576;

/** Whole MiB, half up, from a byte count (a safe integer: no fraction is ever kept). */
export function wholeMb(bytes: number | null): number | null {
  if (bytes === null) return null;
  return Math.floor((Math.max(0, bytes) + MIB / 2) / MIB);
}
