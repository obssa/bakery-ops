import type { Api } from "./api";

// Fixtures for the p4-dashboards flows (step 4.5e, docs/phases/phase-4-plan.md section 7). The summaries of a day are rebuilt by hand through
// POST /analytics/rebuild with sync (at most 7 days, inline), as the live E2E does: no wait for the queue's touch of today (60 s out, then the
// cron on the live host) and none for the nightly run. Only the E2E Test Branch's own days are rebuilt (the admin's branch).

export interface RebuiltDay {
  day: string;
  trigger: string;
  rows: number;
  version: number;
  changed: boolean;
  duration_ms: number;
}

/** POST /analytics/rebuild {from, to, sync: true} as the admin: the days built here and now, one result a day. */
export async function rebuildDays(admin: Api, from: string, to: string = from): Promise<RebuiltDay[]> {
  const answer = await admin.post<{ from: string; to: string; sync: boolean; days: RebuiltDay[] }>("/analytics/rebuild", { from, to, sync: true });
  return answer.days;
}

/** A local date `days` after (negative: before) another, as the API counts local days. */
export function shiftDay(date: string, days: number): string {
  const [y, m, d] = date.split("-").map((part) => Number(part));
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}
