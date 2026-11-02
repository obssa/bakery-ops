import { expect, test } from "@playwright/test";
import type { PrintJob } from "../../src/lib/api/types";
import {
  failureReason,
  inFlight,
  latestJob,
  PRINT_POLL_MS,
  PRINT_SLOW_POLL_MS,
  printPhase,
  printPollMs,
  qrModules,
  showsLink,
} from "../../src/lib/print";
import { checkReceiptFrame, readEscPos } from "../support/escpos";

// Phase 5 step 5.3c, the logic under the till's Print button (no browser, no stack; run by p5-device): what the till says of a print job
// (claimed shows as printing), when it polls GET /print-jobs?order_id= and how often, when the receipt link and its QR code take over
// (a failed or expired job, or a print refused because no printer is connected or the order is not paid), and the fake bridge's framing
// check of e2e/p5/print.spec.ts (until 5.3b's emulator is on main).

const NOW = Date.parse("2026-11-11T09:00:00Z");

function job(over: Partial<PrintJob> = {}): PrintJob {
  return {
    id: 1,
    order_id: 7,
    status: "queued",
    copies: 1,
    attempts: 0,
    failure_key: null,
    created_at: "2026-11-11T08:59:50Z",
    claimed_at: null,
    printed_at: null,
    failed_at: null,
    expires_at: "2026-11-11T09:14:50Z",
    ...over,
  };
}

test.describe("p5 print logic", () => {
  test("a job's state as the till says it: claimed is printing", () => {
    expect(printPhase("queued")).toBe("waiting");
    expect(printPhase("claimed")).toBe("printing");
    expect(printPhase("printed")).toBe("printed");
    expect(printPhase("failed")).toBe("failed");
    expect(printPhase("expired")).toBe("expired");
  });

  test("the latest job is the newest one, whatever the order of the answer", () => {
    expect(latestJob([])).toBeNull();
    expect(latestJob([job({ id: 4 }), job({ id: 9, status: "printed" }), job({ id: 2 })])?.id).toBe(9);
    expect(inFlight(null)).toBe(false);
    expect(inFlight(job())).toBe(true);
    expect(inFlight(job({ status: "claimed" }))).toBe(true);
    for (const status of ["printed", "failed", "expired"] as const) expect(inFlight(job({ status }))).toBe(false);
  });

  test("the till polls while the latest job is in flight, slower once it has waited a minute, and stops when it is done", () => {
    expect(printPollMs([], NOW)).toBe(false);
    expect(printPollMs([job()], NOW)).toBe(PRINT_POLL_MS);
    expect(printPollMs([job({ status: "claimed" })], NOW)).toBe(PRINT_POLL_MS);
    expect(printPollMs([job({ created_at: "2026-11-11T08:58:30Z" })], NOW)).toBe(PRINT_SLOW_POLL_MS);
    // a claimed job is about to print: the till keeps looking often
    expect(printPollMs([job({ status: "claimed", created_at: "2026-11-11T08:50:00Z" })], NOW)).toBe(PRINT_POLL_MS);
    for (const status of ["printed", "failed", "expired"] as const) expect(printPollMs([job({ status })], NOW)).toBe(false);
    // an older job still queued does not keep the poll alive once a newer one is done
    expect(printPollMs([job({ id: 1 }), job({ id: 2, status: "printed" })], NOW)).toBe(false);
    expect(PRINT_POLL_MS).toBeLessThan(PRINT_SLOW_POLL_MS);
  });

  test("the receipt link and its QR code take over for a failed or expired job, and for a print refused for no printer or no payment", () => {
    expect(showsLink(null, null)).toBe(false);
    for (const status of ["queued", "claimed", "printed"] as const) expect(showsLink(job({ status }), null)).toBe(false);
    expect(showsLink(job({ status: "failed", failure_key: "paper_out" }), null)).toBe(true);
    expect(showsLink(job({ status: "expired" }), null)).toBe(true);
    expect(showsLink(null, "payments.print.no_bridge")).toBe(true);
    expect(showsLink(null, "payments.print.not_paid")).toBe(true);
    expect(showsLink(null, "payments.print.disabled")).toBe(true);
    // any other refusal (a permission, an unenrolled device, the network) is an error, not the fallback
    expect(showsLink(null, "auth.forbidden")).toBe(false);
    expect(showsLink(null, "identity.device.unknown")).toBe(false);
    expect(showsLink(null, "network")).toBe(false);
  });

  test("a failure key the till does not know reads as unknown", () => {
    for (const key of ["paper_out", "offline", "cover_open", "unknown", "unacknowledged"] as const) expect(failureReason(key)).toBe(key);
    expect(failureReason(null)).toBe("unknown");
    expect(failureReason("jammed")).toBe("unknown");
  });

  test("the QR code of a receipt link is a square of modules with its three finder patterns", () => {
    const url = "https://bakery.example/api/v1/receipts/abc?expires=1762900000&signature=0123456789abcdef0123456789abcdef";
    const qr = qrModules(url);
    expect(qr.size).toBeGreaterThanOrEqual(21);
    expect((qr.size - 17) % 4).toBe(0);
    expect(qr.dark.length).toBeGreaterThan(0);
    const dark = new Set(qr.dark.map(([x, y]) => `${x},${y}`));
    // the finder patterns' outer rings at the top left, top right and bottom left
    for (const [x, y] of [
      [0, 0],
      [6, 0],
      [0, 6],
      [qr.size - 1, 0],
      [qr.size - 7, 6],
      [0, qr.size - 1],
      [6, qr.size - 7],
    ])
      expect(dark.has(`${x},${y}`)).toBe(true);
    // the separators beside them are light
    expect(dark.has("7,0")).toBe(false);
    expect(dark.has(`${qr.size - 8},0`)).toBe(false);
    // the same link draws the same code
    expect(qrModules(url)).toEqual(qr);
    expect(qrModules(`${url}x`)).not.toEqual(qr);
  });

  test("the fake bridge's framing check: ESC @ first, GS v 0 bands of one width, the GS V cut last", () => {
    const band = (widthBytes: number, rows: number) => [0x1d, 0x76, 0x30, 0, widthBytes, 0, rows, 0, ...new Array(widthBytes * rows).fill(0)];
    const copy = [0x1b, 0x40, ...band(72, 3), ...band(72, 2), 0x1b, 0x64, 4, 0x1d, 0x56, 1];
    expect(checkReceiptFrame(new Uint8Array(copy))).toEqual({ copies: 1, widthDots: 576, rows: 5, bands: 2 });
    expect(checkReceiptFrame(new Uint8Array([...copy, ...copy])).copies).toBe(2);
    expect(checkReceiptFrame(new Uint8Array([0x1b, 0x40, ...band(48, 1), 0x1d, 0x56, 1])).widthDots).toBe(384);
    expect(readEscPos(new Uint8Array([0x1b, 0x40, 0x41, 0x42, 0x0a]))).toEqual([{ op: "init" }, { op: "text", bytes: 3 }]);

    expect(() => checkReceiptFrame(new Uint8Array(copy.slice(2)))).toThrow(/not ESC @/);
    expect(() => checkReceiptFrame(new Uint8Array(copy.slice(0, -3)))).toThrow(/not the GS V cut/);
    expect(() => checkReceiptFrame(new Uint8Array([0x1b, 0x40, 0x1d, 0x56, 1]))).toThrow(/no GS v 0 raster/);
    expect(() => checkReceiptFrame(new Uint8Array([0x1b, 0x40, ...band(72, 1), ...band(48, 1), 0x1d, 0x56, 1]))).toThrow(/bands of 72 and 48/);
    expect(() => readEscPos(new Uint8Array([0x1b, 0x40, 0x1d, 0x76, 0x30, 0, 72, 0, 2, 0, 1, 2, 3]))).toThrow(/cut short/);
  });
});
