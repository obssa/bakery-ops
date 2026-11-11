import { expect, test } from "@playwright/test";
import type { Me, ProductionRequest } from "../../src/lib/api/types";
import {
  batchAge,
  boardSummary,
  dropMove,
  nextMover,
  ovenAge,
  requestWait,
  STALE_AFTER,
  URGENT_TOO_LONG_MINUTES,
  WAIT_TOO_LONG_MINUTES,
} from "../../src/lib/productionBoard";
import { columnCount } from "../../src/lib/live/production";

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
    expect(summary).toEqual({ counts: { requested: 3, in_progress: 3, pending_confirmation: 1, done_today: 0 }, urgent: 2, waiting: 0, stale: 2 });
  });

  test("an empty board sums to nothing", () => {
    expect(boardSummary({ requested: [], in_progress: [], pending_confirmation: [], done_today: [] }, NOW)).toEqual({
      counts: { requested: 0, in_progress: 0, pending_confirmation: 0, done_today: 0 },
      urgent: 0,
      waiting: 0,
      stale: 0,
    });
  });
});

// phase-6/board-drag (Ramin 2026-11-11): how long a request nobody has started has waited, when it waits too long or is late, what a drop on
// each column does for each person, and who moves a card next.

function person(id: number, permissions: string[], stations: { station_id: number; is_overseer: boolean }[] = []): Me {
  return {
    user: { id, name: `Person ${id}`, stations: stations.map((s) => ({ ...s, code: "bakery", name_en: "Bakery", name_am: "ዳቦ ቤት" })) },
    permissions,
    locale: "en",
    acting: null,
    device: null,
    work_stations: [],
  } as unknown as Me;
}

const baker = person(10, ["production.fulfil"]);
const otherBaker = person(11, ["production.fulfil"]);
const manager = person(20, ["production.confirm", "production.plan"]);
const overseer = person(21, [], [{ station_id: 1, is_overseer: true }]);
const cashier = person(30, ["production.request"]);
const ref = (who: Me) => ({ id: who.user.id, name: who.user.name });

function finished(id: number, by: Me): ProductionRequest {
  const oven = inOven(id, 3600, "finished");
  return { ...oven, status: "pending_confirmation", assigned_to: ref(by), batch: oven.batch ? { ...oven.batch, finished_by: ref(by) } : null };
}

test.describe("the production board's waits, drops and next movers", () => {
  test("a request waits too long after two hours, an urgent one after half an hour, and is late past its due time", () => {
    expect(WAIT_TOO_LONG_MINUTES).toBe(120);
    expect(URGENT_TOO_LONG_MINUTES).toBe(30);
    expect(requestWait(request({ created_at: ago(119 * 60 + 59) }), NOW)).toEqual({ seconds: 119 * 60 + 59, tooLong: false, lateSeconds: null });
    expect(requestWait(request({ created_at: ago(120 * 60) }), NOW)?.tooLong).toBe(true);
    expect(requestWait(request({ created_at: ago(29 * 60), priority: "urgent" }), NOW)?.tooLong).toBe(false);
    expect(requestWait(request({ created_at: ago(30 * 60), priority: "urgent" }), NOW)?.tooLong).toBe(true);
    expect(requestWait(request({ created_at: ago(600), status: "accepted", due_at: ago(90) }), NOW)).toEqual({ seconds: 600, tooLong: false, lateSeconds: 90 });
    expect(requestWait(request({ created_at: ago(600), due_at: new Date(NOW + 60_000).toISOString() }), NOW)?.lateSeconds).toBeNull();
  });

  test("only a request nobody has started waits; one without an age does not", () => {
    expect(requestWait(inOven(1, 999_999), NOW)).toBeNull();
    expect(requestWait(request({ status: "pending_confirmation", created_at: ago(99_999) }), NOW)).toBeNull();
    expect(requestWait(request({ created_at: null }), NOW)).toBeNull();
  });

  test("the summary counts the requests waiting too long or late", () => {
    const summary = boardSummary(
      {
        requested: [request({ id: 1, created_at: ago(3 * 3600) }), request({ id: 2, created_at: ago(60), due_at: ago(1) }), request({ id: 3, created_at: ago(60) })],
        in_progress: [],
        pending_confirmation: [],
        done_today: [],
      },
      NOW,
    );
    expect(summary.waiting).toBe(2);
  });

  test("a baker drags a request nobody has to the oven: accepted and started in one drop", () => {
    expect(dropMove(baker, request({}), "in_progress")).toEqual({ move: "accept_start" });
    expect(dropMove(baker, request({ assigned_to: ref(baker) }), "in_progress")).toEqual({ move: "accept_start" });
    expect(dropMove(baker, request({ status: "accepted", assigned_to: ref(baker) }), "in_progress")).toEqual({ move: "start" });
  });

  test("nobody else takes a request given to or accepted by someone, and a non-baker starts nothing", () => {
    expect(dropMove(otherBaker, request({ assigned_to: ref(baker) }), "in_progress")).toEqual({ refused: { reason: "drop_given_to", name: "Person 10" } });
    expect(dropMove(otherBaker, request({ status: "accepted", assigned_to: ref(baker) }), "in_progress")).toEqual({ refused: { reason: "drop_not_yours_start", name: "Person 10" } });
    expect(dropMove(manager, request({}), "in_progress")).toEqual({ refused: { reason: "drop_not_baker" } });
    expect(dropMove(cashier, request({}), "in_progress")).toEqual({ refused: { reason: "drop_not_baker" } });
  });

  test("a card moves one step at a time and never back to Requested once started; its own column does nothing", () => {
    expect(dropMove(baker, request({}), "pending_confirmation")).toEqual({ refused: { reason: "drop_no_skip" } });
    expect(dropMove(baker, request({}), "done_today")).toEqual({ refused: { reason: "drop_no_skip" } });
    expect(dropMove(baker, request({}), "requested")).toBeNull();
    const mine = { ...inOven(5, 600), assigned_to: ref(baker) };
    expect(dropMove(baker, mine, "requested")).toEqual({ refused: { reason: "drop_no_back" } });
    expect(dropMove(manager, finished(6, baker), "requested")).toEqual({ refused: { reason: "drop_no_back" } });
  });

  test("where an overseer is needed, the baker who started a batch finishes it on Pending confirmation; Done today is a second step", () => {
    const mine = { ...inOven(5, 600), assigned_to: ref(baker) };
    expect(mine.station.requires_overseer).toBe(true);
    expect(dropMove(baker, mine, "pending_confirmation")).toEqual({ move: "finish" });
    expect(dropMove(baker, mine, "done_today")).toEqual({ refused: { reason: "drop_finish_first" } });
    expect(dropMove(manager, mine, "done_today")).toEqual({ refused: { reason: "drop_finish_first" } });
    expect(dropMove(otherBaker, mine, "pending_confirmation")).toEqual({ refused: { reason: "drop_not_yours_finish", name: "Person 10" } });
    expect(dropMove(manager, mine, "pending_confirmation")).toEqual({ refused: { reason: "drop_not_yours_finish", name: "Person 10" } });
  });

  test("where no overseer is needed, finishing goes straight to Done today and Pending confirmation is never used", () => {
    const oven = inOven(8, 600);
    const mine = { ...oven, assigned_to: ref(baker), station: { ...oven.station, requires_overseer: false } };
    expect(dropMove(baker, mine, "done_today")).toEqual({ move: "finish" });
    expect(dropMove(baker, mine, "pending_confirmation")).toEqual({ refused: { reason: "drop_no_overseer" } });
    expect(dropMove(otherBaker, mine, "done_today")).toEqual({ refused: { reason: "drop_not_yours_finish", name: "Person 10" } });
  });

  test("an overseer or a manager confirms a batch someone else made, or sends it back; never their own", () => {
    expect(dropMove(manager, finished(6, baker), "done_today")).toEqual({ move: "confirm" });
    expect(dropMove(overseer, finished(6, baker), "done_today")).toEqual({ move: "confirm" });
    expect(dropMove(manager, finished(6, baker), "in_progress")).toEqual({ move: "send_back" });
    expect(dropMove(baker, finished(6, baker), "done_today")).toEqual({ refused: { reason: "drop_not_overseer" } });
    const ownManager = finished(7, manager);
    expect(dropMove(manager, ownManager, "done_today")).toEqual({ refused: { reason: "drop_own_batch", name: "Person 20" } });
  });

  test("a confirmed batch stays in Done today", () => {
    expect(dropMove(manager, request({ status: "done" }), "pending_confirmation")).toEqual({ refused: { reason: "drop_done" } });
  });

  test("a card says who moves it next", () => {
    expect(nextMover(cashier, request({}))).toEqual({ key: "next_accept_any" });
    expect(nextMover(cashier, request({ assigned_to: ref(baker) }))).toEqual({ key: "next_accept_named", name: "Person 10" });
    expect(nextMover(cashier, request({ status: "accepted", assigned_to: ref(baker) }))).toEqual({ key: "next_start", name: "Person 10" });
    expect(nextMover(cashier, { ...inOven(5, 60), assigned_to: ref(baker) })).toEqual({ key: "next_finish", name: "Person 10" });
    expect(nextMover(cashier, finished(6, baker))).toEqual({ key: "next_confirm_not", name: "Person 10" });
    expect(nextMover(baker, finished(6, baker))).toEqual({ key: "next_confirm_mine" });
    expect(nextMover(cashier, request({ status: "done" }))).toBeNull();
  });
});

test("a column's true count comes from the branch's counts, Requested holding the accepted ones too", () => {
  const counts = { requested: 107, accepted: 3, in_progress: 97, pending_confirmation: 2, done_today: 5 };
  expect(columnCount(counts, "requested")).toBe(110);
  expect(columnCount(counts, "in_progress")).toBe(97);
  expect(columnCount(counts, "pending_confirmation")).toBe(2);
  expect(columnCount(counts, "done_today")).toBe(5);
});
