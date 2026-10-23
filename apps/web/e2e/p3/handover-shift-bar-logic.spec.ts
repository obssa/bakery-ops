import { expect, test } from "@playwright/test";
import { ApiError, conditionalGet } from "../../src/lib/api/client";
import type { ConditionalResult } from "../../src/lib/api/client";
import type { Shift, ShiftCurrentPage, WorkStation } from "../../src/lib/api/types";
import { LiveFeed } from "../../src/lib/live/feed";
import { SHIFT_POLL, shiftSpec } from "../../src/lib/live/shifts";
import { addisClock, barActions, chooseStation, isOnDuty, onDuty, payBlockReason, shiftRequiredNotice, stationsFor } from "../../src/lib/shifts";
import { useShiftNotice } from "../../src/lib/station";

// Step 3.7a, the parts of the shift bar that are plain logic (no browser, no API): which buttons a person gets in each state of
// the shift, who counts as on duty, when the till's Pay button is blocked, how the two "shift required" refusals are recognised,
// the poll cadence and ETag handling of the shift feed, and the request timeout that keeps a hung poll from freezing a feed.
// They ride in the p3-handover project (its name matches), next to the flow in handover-shift-bar.spec.ts.

function shift(patch: Partial<Shift> = {}): Shift {
  return {
    id: 7,
    client_uuid: "00000000-0000-4000-8000-000000000007",
    station: { id: 3, code: "kitchen", name_en: "Kitchen", name_am: "ኩሽና", tracks_shifts: true, requires_overseer: false },
    template: null,
    date: "2026-10-23",
    status: "open",
    origin: "started",
    opening_mode: "carried",
    starts_at: null,
    ends_at: null,
    started_by: { id: 11, name: "Abebe" },
    started_at: "2026-10-23T05:00:00Z",
    opened_by: { id: 11, name: "Abebe" },
    opened_at: "2026-10-23T05:01:00Z",
    closed_by: null,
    closing_started_at: null,
    me_on_roster: false,
    version: 1,
    roster: [
      { user: { id: 11, name: "Abebe" }, source: "started", joined_at: "2026-10-23T05:00:00Z", left_at: null },
      { user: { id: 12, name: "Selam" }, source: "joined", joined_at: "2026-10-23T06:00:00Z", left_at: "2026-10-23T07:00:00Z" },
    ],
    counts: [],
    notes_count: 0,
    ...patch,
  };
}

const crew = { canCount: true, canManage: false };

test.describe("p3 shift bar: logic", () => {
  test("on duty means on the roster and not left", () => {
    const s = shift();
    expect(onDuty(s).map((r) => r.user?.name)).toEqual(["Abebe"]);
    expect(isOnDuty(s, 11)).toBe(true);
    expect(isOnDuty(s, 12)).toBe(false); // left at 07:00
    expect(isOnDuty(s, 99)).toBe(false);
    expect(isOnDuty(null, 11)).toBe(false);
  });

  test("an idle station offers both starts to someone who may count, and nothing to anyone else", () => {
    expect(barActions(null, 11, crew)).toMatchObject({ mode: "idle", start: true, startCarried: true, join: false, close: false });
    expect(barActions(null, 11, { canCount: false, canManage: false })).toMatchObject({ mode: "idle", start: false, startCarried: false });
  });

  test("an open shift: join when not on it, leave and close when on it", () => {
    expect(barActions(shift(), 12, crew)).toMatchObject({ mode: "open", join: true, leave: false, close: true, start: false });
    expect(barActions(shift(), 11, crew)).toMatchObject({ mode: "open", join: false, leave: true, close: true });
    expect(barActions(shift(), 11, { canCount: false, canManage: false })).toMatchObject({ join: false, leave: false, close: false });
  });

  test("an opening shift: continue the count, and only its starter or a manager may cancel it", () => {
    const opening = shift({ status: "opening", opening_mode: "counted" });
    expect(barActions(opening, 11, crew)).toMatchObject({ mode: "opening", continueCount: true, cancel: true, close: false, join: false });
    expect(barActions(opening, 12, crew)).toMatchObject({ continueCount: true, cancel: false });
    expect(barActions(opening, 12, { canCount: true, canManage: true })).toMatchObject({ cancel: true });
  });

  test("a counting shift (closing) goes to the count and offers nothing else", () => {
    expect(barActions(shift({ status: "counting" }), 11, crew)).toMatchObject({ mode: "counting", continueCount: true, close: false, join: false, start: false });
  });

  test("the till's Pay is blocked only where enforcement is known and no shift is open", () => {
    const base = { tracked: true, loaded: true, enforced: true };
    expect(payBlockReason({ ...base, shift: null })).toBe("no_shift");
    expect(payBlockReason({ ...base, shift: shift({ status: "opening" }) })).toBe("not_open");
    expect(payBlockReason({ ...base, shift: shift({ status: "counting" }) })).toBe("not_open");
    expect(payBlockReason({ ...base, shift: shift() })).toBeNull();
    // enforcement not known (the flag is off): Pay stays possible, the bar only shows the state
    expect(payBlockReason({ ...base, enforced: false, shift: null })).toBeNull();
    // a station that tracks no shifts, or a feed that has not answered yet, never blocks
    expect(payBlockReason({ ...base, tracked: false, shift: null })).toBeNull();
    expect(payBlockReason({ ...base, loaded: false, shift: null })).toBeNull();
  });

  test("the two shift-required refusals are recognised by status and key", () => {
    const inventory = new ApiError(409, { message_key: "inventory.shift.required", message_en: "No shift", message_am: "ፈረቃ የለም", station_id: 3, location_id: 9 } as never);
    const payments = new ApiError(409, { message_key: "payments.shift.required", message_en: "Start your shift" });
    expect(shiftRequiredNotice(inventory)).toEqual({ kind: "inventory", stationId: 3 });
    expect(shiftRequiredNotice(payments)).toEqual({ kind: "payments", stationId: null });
    expect(shiftRequiredNotice(new ApiError(409, { message_key: "orders.send.shortfall" }))).toBeNull();
    expect(shiftRequiredNotice(new ApiError(500, { message_key: "inventory.shift.required" }))).toBeNull();
    expect(shiftRequiredNotice(new Error("x"))).toBeNull();
  });

  test("the notice store keeps the last refusal until the shift is started", () => {
    useShiftNotice.getState().clear();
    expect(useShiftNotice.getState().notice).toBeNull();
    useShiftNotice.getState().report(new ApiError(409, { message_key: "payments.shift.required", message_en: "Start your shift", message_am: "ፈረቃዎን ይጀምሩ" }));
    expect(useShiftNotice.getState().notice).toMatchObject({ kind: "payments", message_en: "Start your shift", message_am: "ፈረቃዎን ይጀምሩ" });
    // an unrelated failure leaves it alone
    useShiftNotice.getState().report(new ApiError(422, { message_key: "validation.failed" }));
    expect(useShiftNotice.getState().notice).not.toBeNull();
    // the payments refusal also teaches the till that cash needs a shift here
    expect(useShiftNotice.getState().cashNeedsShift).toBe(true);
    useShiftNotice.getState().clear();
    expect(useShiftNotice.getState().notice).toBeNull();
    expect(useShiftNotice.getState().cashNeedsShift).toBe(true); // what was learned stays
  });

  test("the stations of a screen: the ones that track shifts with the right code, and a choice that falls back", () => {
    const ws = (id: number, code: WorkStation["code"], tracks = true): WorkStation => ({
      station_id: id,
      code,
      name_en: code,
      name_am: code,
      tracks_shifts: tracks,
      requires_overseer: false,
      current_shift: null,
    });
    const all = [ws(1, "store", false), ws(2, "till"), ws(3, "kitchen"), ws(4, "cake_room"), ws(5, "bakery"), ws(6, "bar")];
    expect(stationsFor(all, ["kitchen"]).map((s) => s.station_id)).toEqual([3]);
    expect(stationsFor(all, ["cake_room", "bakery"]).map((s) => s.station_id)).toEqual([4, 5]);
    expect(stationsFor(all, ["store"])).toEqual([]); // a station that tracks no shifts has none
    const choices = stationsFor(all, ["cake_room", "bakery"]);
    expect(chooseStation(choices, 5)?.station_id).toBe(5);
    expect(chooseStation(choices, 99)?.station_id).toBe(4);
    expect(chooseStation(choices, null)?.station_id).toBe(4);
    expect(chooseStation([], 5)).toBeNull();
  });

  test("times are shown in Addis Ababa", () => {
    expect(addisClock("2026-10-23T05:30:00Z")).toBe("08:30");
    expect(addisClock("2026-10-23T21:05:00Z")).toBe("00:05");
    expect(addisClock(null)).toBe("");
  });
});

test.describe("p3 shift bar: the shift feed", () => {
  test("polls every 5 s while visible and every 30 s while hidden", () => {
    expect(SHIFT_POLL).toEqual({ intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 });
  });

  test("a page replaces the state, a 304 keeps it, and the ETag of the last answer is sent next", async () => {
    const seen: (string | null)[] = [];
    const page = (data: Shift | null): ShiftCurrentPage => ({ data, server_time: "2026-10-23T05:00:00Z" });
    const answers: ConditionalResult<ShiftCurrentPage>[] = [
      { notModified: false, etag: '"a"', data: page(shift({ version: 1 })) },
      { notModified: true, etag: '"a"' },
      { notModified: false, etag: '"b"', data: page(null) },
    ];
    const feed = new LiveFeed(
      {
        async fetchPage({ etag }) {
          seen.push(etag);
          return answers.shift() as ConditionalResult<ShiftCurrentPage>;
        },
      },
      shiftSpec,
      { start: () => ({ stop() {}, kick() {} }) },
    );
    expect(await feed.poll()).toBe("changed");
    expect(feed.getSnapshot().state.shift?.version).toBe(1);
    expect(await feed.poll()).toBe("unchanged");
    expect(feed.getSnapshot().state.shift?.version).toBe(1);
    expect(await feed.poll()).toBe("changed");
    expect(feed.getSnapshot().state.shift).toBeNull(); // the shift was closed: null is an answer, not "nothing"
    expect(feed.getSnapshot().loaded).toBe(true);
    expect(seen).toEqual([null, '"a"', '"a"']);
  });
});

// Carry from step 3.4 (the pad menu that did not show a cake that was back in stock): the one way a poll can stop for good is a
// request that never answers, because a look waits for its fetch and the next look is scheduled after it. A request now gives up.
test.describe("p3 live feeds: a hung request gives up", () => {
  test("conditionalGet fails as a network error after its timeout instead of waiting for ever", async () => {
    const real = globalThis.fetch;
    globalThis.fetch = ((_url: unknown, init?: RequestInit) =>
      new Promise((_resolve, reject) => {
        init?.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
      })) as typeof fetch;
    try {
      const started = Date.now();
      const failure = await conditionalGet("/menu", { timeoutMs: 80 }).catch((error: unknown) => error);
      expect(failure).toBeInstanceOf(ApiError);
      expect((failure as ApiError).isNetwork).toBe(true);
      expect(Date.now() - started).toBeLessThan(2000);
    } finally {
      globalThis.fetch = real;
    }
  });

  test("a caller that aborts still gets an AbortError, not a network error", async () => {
    const real = globalThis.fetch;
    globalThis.fetch = ((_url: unknown, init?: RequestInit) =>
      new Promise((_resolve, reject) => {
        init?.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
      })) as typeof fetch;
    try {
      const controller = new AbortController();
      const pending = conditionalGet("/menu", { signal: controller.signal, timeoutMs: 5000 }).catch((error: unknown) => error);
      controller.abort();
      const failure = await pending;
      expect(failure).toBeInstanceOf(DOMException);
    } finally {
      globalThis.fetch = real;
    }
  });
});
