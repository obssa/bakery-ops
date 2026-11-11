import { expect, test } from "@playwright/test";
import type { ProductionRequest } from "../../src/lib/api/types";
import { batchAge, boardSummary, ovenAge, STALE_AFTER } from "../../src/lib/productionBoard";

// The production board's own arithmetic (phase-6/production-board, lib/productionBoard.ts): how long a batch has been in the oven, when it
// counts as stale (a day or more: it was left on the board unfinished), and the summary strip's counts, urgent requests and stale batches.
// Plain functions, no page: it runs in p6-a11y beside the screenshots of the board.

const NOW = Date.parse("2026-11-11T09:00:00Z");
const ago = (seconds: number) => new Date(NOW - seconds * 1000).toISOString();

function request(over: Partial<ProductionRequest>): ProductionRequest {
  return {
    id: 1,
    client_uuid: "u",
    product: { id: 1, name_en: "Rolls", name_am: "ዳቦ", unit: "pcs" },
    requested_qty: "80",
    due_at: null,
    priority: "normal",
    reason: "schedule",
    status: "requested",
    station: { id: 1, code: "bakery", name_en: "Bakery", name_am: "ዳቦ ቤት", requires_overseer: true },
    requested_by: null,
    assigned_to: null,
    note: null,
    accepted_at: null,
    done_at: null,
    cancelled_at: null,
    cancel_reason: null,
    batch: null,
    created_at: null,
    version: 1,
    ...over,
  };
}

function inOven(id: number, startedSecondsAgo: number, status: "started" | "finished" = "started"): ProductionRequest {
  return request({
    id,
    status: "in_progress",
    batch: {
      id,
      client_uuid: `b${id}`,
      status,
      recipe_version: 1,
      planned_qty: "80",
      produced_qty: null,
      waste_qty: null,
      started_at: ago(startedSecondsAgo),
      finished_by: null,
      finished_at: null,
      confirmed_by: null,
      confirmed_at: null,
      send_back_note: null,
      send_back_count: 0,
      version: 1,
    },
  });
}

test.describe("the production board's ages and summary", () => {
  test("a batch's age is whole seconds and whole days, never negative", () => {
    expect(batchAge(ago(0), NOW)).toEqual({ seconds: 0, days: 0, stale: false });
    expect(batchAge(ago(42.9), NOW)).toEqual({ seconds: 42, days: 0, stale: false });
    expect(batchAge(ago(-30), NOW)).toEqual({ seconds: 0, days: 0, stale: false });
    expect(batchAge(ago(3 * 86_400 + 5), NOW)).toEqual({ seconds: 3 * 86_400 + 5, days: 3, stale: true });
  });

  test("stale from one day exactly, not a second before", () => {
    expect(STALE_AFTER).toBe(86_400);
    expect(batchAge(ago(86_399), NOW)?.stale).toBe(false);
    expect(batchAge(ago(86_400), NOW)).toEqual({ seconds: 86_400, days: 1, stale: true });
    expect(batchAge(ago(6 * 86_400 + 3600), NOW)?.days).toBe(6);
  });

  test("no age without a readable start", () => {
    expect(batchAge(null, NOW)).toBeNull();
    expect(batchAge(undefined, NOW)).toBeNull();
    expect(batchAge("not a time", NOW)).toBeNull();
    expect(batchAge(ago(10), Number.NaN)).toBeNull();
  });

  test("only a started batch in progress has an oven age", () => {
    expect(ovenAge(inOven(1, 600), NOW)?.seconds).toBe(600);
    expect(ovenAge(inOven(2, 600, "finished"), NOW)).toBeNull();
    expect(ovenAge(request({ status: "requested" }), NOW)).toBeNull();
    expect(ovenAge(request({ status: "in_progress", batch: null }), NOW)).toBeNull();
    expect(ovenAge({ ...inOven(3, 600), status: "pending_confirmation" }, NOW)).toBeNull();
  });

  test("the summary counts each column, the urgent requests waiting and the stale batches", () => {
    const summary = boardSummary(
      {
        requested: [request({ id: 1, priority: "urgent" }), request({ id: 2, priority: "high" }), request({ id: 3, priority: "urgent", status: "accepted" })],
        in_progress: [inOven(4, 600), inOven(5, 6 * 86_400), inOven(6, 86_400)],
        pending_confirmation: [request({ id: 7, status: "pending_confirmation" })],
        done_today: [],
      },
      NOW,
    );
    expect(summary).toEqual({ counts: { requested: 3, in_progress: 3, pending_confirmation: 1, done_today: 0 }, urgent: 2, stale: 2 });
  });

  test("an empty board sums to nothing", () => {
    expect(boardSummary({ requested: [], in_progress: [], pending_confirmation: [], done_today: [] }, NOW)).toEqual({
      counts: { requested: 0, in_progress: 0, pending_confirmation: 0, done_today: 0 },
      urgent: 0,
      stale: 0,
    });
  });
});
