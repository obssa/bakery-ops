import { encode } from "uqr";
import type { PrintJob, PrintJobStatus } from "./api/types";

// Printing from the till (step 5.3c, phase-5 plan 4.3 and ADR 0019): what the till says of a print job, when it looks again, and when the
// receipt link takes over. The API keeps the job (POST /orders/{order}/print, step 5.3a); a print bridge on a shop computer claims it and
// says whether it printed. The till polls GET /print-jobs?order_id= (conditional, ETag) only while its latest job is in flight.
//
// The receipt link and its QR code (4.3e's signed link) take over when a job fails (three tries) or expires (15 minutes), and when the
// print is refused at once: no bridge of the branch was seen in the last two minutes (payments.print.no_bridge), or the order is not
// paid (payments.print.not_paid), or the admin turned printing off (payments.print.disabled; a till that knows it shows the link at once,
// with no Print). The customer scans the code with a phone and keeps the receipt there.

/** What the till says: a claimed job is a bridge printing it. */
export type PrintPhase = "waiting" | "printing" | "printed" | "failed" | "expired";

const PHASE: Record<PrintJobStatus, PrintPhase> = {
  queued: "waiting",
  claimed: "printing",
  printed: "printed",
  failed: "failed",
  expired: "expired",
};

export function printPhase(status: PrintJobStatus): PrintPhase {
  return PHASE[status];
}

/** The order's newest job (the API answers newest first; the highest id wins whatever the order). */
export function latestJob(jobs: readonly PrintJob[]): PrintJob | null {
  let latest: PrintJob | null = null;
  for (const job of jobs) if (!latest || job.id > latest.id) latest = job;
  return latest;
}

/** Waiting or printing: the outcome is still to come. */
export function inFlight(job: PrintJob | null): boolean {
  return job !== null && (job.status === "queued" || job.status === "claimed");
}

/** How often the till looks while a job is in flight: a bridge polls every 3 s, so a receipt usually prints within a few seconds. */
export const PRINT_POLL_MS = 2000;
/** A job still waiting after a minute: the bridge is probably away; look less often until it prints or expires (15 minutes). */
export const PRINT_SLOW_POLL_MS = 10_000;
const SLOW_AFTER_MS = 60_000;

/** The poll's interval for react-query's refetchInterval: false once the latest job is done (or there is none). */
export function printPollMs(jobs: readonly PrintJob[] | undefined, now: number = Date.now()): number | false {
  const job = latestJob(jobs ?? []);
  if (!job || !inFlight(job)) return false;
  if (job.status === "claimed") return PRINT_POLL_MS;
  const age = now - Date.parse(job.created_at);
  return Number.isFinite(age) && age > SLOW_AFTER_MS ? PRINT_SLOW_POLL_MS : PRINT_POLL_MS;
}

/** The go-live settings (2026-11-02): the admin turned printing off in the receipt settings (the till hides Print once it knows). */
export const PRINTING_DISABLED = "payments.print.disabled";

/** The refusals of POST /orders/{order}/print that the receipt link answers instead of an error. */
export const PRINT_FALLBACK_REFUSALS: readonly string[] = ["payments.print.no_bridge", "payments.print.not_paid", PRINTING_DISABLED];

/** Whether the receipt link and its QR code take over: the latest job failed or expired, or the print was refused for no printer. */
export function showsLink(job: PrintJob | null, refusal: string | null): boolean {
  if (refusal !== null && PRINT_FALLBACK_REFUSALS.includes(refusal)) return true;
  return job !== null && (job.status === "failed" || job.status === "expired");
}

export const PRINT_FAILURE_KEYS = ["paper_out", "offline", "cover_open", "unknown", "unacknowledged"] as const;
export type PrintFailure = (typeof PRINT_FAILURE_KEYS)[number];

/** A failure key the till can say; one it does not know reads as unknown. */
export function failureReason(key: string | null): PrintFailure {
  return (PRINT_FAILURE_KEYS as readonly string[]).includes(key ?? "") ? (key as PrintFailure) : "unknown";
}

/** The dark modules of a QR code of `text` (no quiet zone; the picture adds it), error correction M so a creased screen still scans. */
export function qrModules(text: string): { size: number; dark: [number, number][] } {
  const qr = encode(text, { ecc: "M", border: 0 });
  const dark: [number, number][] = [];
  qr.data.forEach((row, y) => row.forEach((on, x) => (on ? dark.push([x, y]) : undefined)));
  return { size: qr.size, dark };
}
