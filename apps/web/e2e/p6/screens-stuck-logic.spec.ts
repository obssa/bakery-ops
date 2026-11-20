import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import type { Me, ProductionRequest, Shift } from "../../src/lib/api/types";
import { adoptDeviceId, deviceId, newDeviceId } from "../../src/lib/device";
import { canSignOff } from "../../src/lib/live/production";
import { voidPath } from "../../src/lib/orderRecord";
import { cancelWaits, LINE_KINDS, lineWaits, TAP_HOLD_MS, tapHolds, type OutboxItem } from "../../src/lib/outbox/logic";
import { landed, SENT } from "../../src/lib/outbox/tray";
import { cardMove, nextColumn, refusedMoves, type CardMove, type ShownMove } from "../../src/lib/productionBoard";
import { barActions, barMode } from "../../src/lib/shifts";
import { useActiveStation } from "../../src/lib/station";

// phase-6/stuck-screens: the plain logic under items 16 to 23 of the implicit-state-machine review (no browser, no stack). Each block names
// the screen that could get stuck or say something untrue, and what it does now.

type Status = OrderStatusLike;
type OrderStatusLike = "open" | "sent" | "in_progress" | "ready" | "served" | "paid" | "closed" | "cancelled" | "voided";
const ORDER_STATUSES: readonly Status[] = ["open", "sent", "in_progress", "ready", "served", "paid", "closed", "cancelled", "voided"];

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

function batch(status: "started" | "finished" | "confirmed", finishedBy: { id: number; name: string } | null): NonNullable<ProductionRequest["batch"]> {
  return {
    id: 5,
    client_uuid: "b5",
    status,
    recipe_version: 1,
    planned_qty: "80",
    produced_qty: null,
    waste_qty: null,
    started_at: "2026-11-20T06:00:00Z",
    finished_by: finishedBy,
    finished_at: null,
    confirmed_by: null,
    confirmed_at: null,
    send_back_note: null,
    send_back_count: 0,
    version: 1,
  };
}

/** The card's rules as they were written out by hand before (lib/live/production.ts), kept here to prove the derived ones match. */
function handWritten(me: Me | null, r: ProductionRequest): CardMove | null {
  if (!me) return null;
  const fulfils = me.permissions.includes("production.fulfil");
  const mine = r.assigned_to?.id === me.user.id;
  switch (r.status) {
    case "requested":
      return fulfils && (r.assigned_to === null || mine) ? "accept" : null;
    case "accepted":
      return fulfils && mine ? "start" : null;
    case "in_progress":
      return fulfils && mine && r.batch?.status === "started" ? "finish" : null;
    case "pending_confirmation":
      return r.batch !== null && r.batch.finished_by?.id !== me.user.id && canSignOff(me, r.station.id) ? "confirm" : null;
    default:
      return null;
  }
}

function item(over: Partial<OutboxItem>): OutboxItem {
  return {
    id: "i1",
    seq: 1,
    kind: "accept_line",
    method: "POST",
    path: "/x",
    body: {},
    idempotencyKey: "k",
    subjectId: null,
    subjectUuid: null,
    group: null,
    dependsOn: null,
    needs: null,
    inline: false,
    userId: 1,
    screen: null,
    createdAt: 0,
    attempts: 0,
    nextAttemptAt: 0,
    state: "pending",
    conflict: null,
    ...over,
  };
}

test.describe("16: Enrol this device keeps a new id only once it is enrolled", () => {
  test("a new id is made without becoming this device's id; adopting it does", () => {
    const before = deviceId();
    const proposed = newDeviceId();
    expect(proposed).toMatch(/^dev-/);
    expect(proposed).not.toBe(before);
    // a failed enrolment adopts nothing: the device still sends its old id (one enrolled in another branch stays enrolled there)
    expect(deviceId()).toBe(before);
    expect(adoptDeviceId(proposed)).toBe(proposed);
    expect(deviceId()).toBe(proposed);
  });
});

test.describe("17: the production board's card and drag follow one table of rules", () => {
  const baker = person(10, ["production.fulfil"]);
  const otherBaker = person(11, ["production.fulfil"]);
  const manager = person(20, ["production.confirm", "production.plan"]);
  const overseer = person(21, [], [{ station_id: 1, is_overseer: true }]);
  const cashier = person(30, ["production.request"]);
  const ref = (who: Me) => ({ id: who.user.id, name: who.user.name });

  test("the card's button is the drop on its next column, for every person and every state", () => {
    const people: (Me | null)[] = [null, baker, otherBaker, manager, overseer, cashier];
    const assignees = [null, ref(baker), ref(otherBaker)];
    const makers = [null, ref(baker), ref(overseer), ref(manager)];
    const cases: ProductionRequest[] = [];
    for (const overseen of [true, false]) {
      const station = { id: 1, code: "bakery" as const, name_en: "Bakery", name_am: "ዳቦ ቤት", requires_overseer: overseen };
      for (const assigned_to of assignees) {
        cases.push(request({ station, assigned_to, status: "requested" }));
        cases.push(request({ station, assigned_to, status: "accepted" }));
        for (const status of ["started", "finished"] as const) cases.push(request({ station, assigned_to, status: "in_progress", batch: batch(status, null) }));
        for (const maker of makers) cases.push(request({ station, assigned_to, status: "pending_confirmation", batch: batch("finished", maker) }));
        cases.push(request({ station, assigned_to, status: "pending_confirmation", batch: null }));
        cases.push(request({ station, assigned_to, status: "done", batch: batch("confirmed", null) }));
        cases.push(request({ station, assigned_to, status: "cancelled" }));
      }
    }
    // the API lists what the person may do (ADR 0032); here the old hand-written rules stand in for it, and the card's button must be the
    // move the list allows on the card's next column, for every person and every state; with nothing listed there is no button at all
    const listed = (who: Me | null, r: ProductionRequest): ProductionRequest => {
      const move = handWritten(who, r);
      return { ...r, actions: move === null ? [] : move === "confirm" ? ["confirm", "send_back"] : [move] };
    };
    for (const who of people) {
      for (const r of cases) {
        expect(cardMove(who, listed(who, r)), `${who?.user.id ?? "nobody"} on ${r.status}`).toBe(handWritten(who, r));
        expect(cardMove(who, { ...r, actions: [] }), `${who?.user.id ?? "nobody"} on ${r.status}, nothing listed`).toBeNull();
      }
    }
  });

  test("the next column: In progress, then where the batch lands (an overseer's station waits in Pending confirmation), then Done today", () => {
    expect(nextColumn(request({ status: "requested" }))).toBe("in_progress");
    expect(nextColumn(request({ status: "accepted" }))).toBe("in_progress");
    expect(nextColumn(request({ status: "in_progress" }))).toBe("pending_confirmation");
    expect(nextColumn(request({ status: "in_progress", station: { id: 1, code: "bakery" as const, name_en: "B", name_am: "B", requires_overseer: false } }))).toBe("done_today");
    expect(nextColumn(request({ status: "pending_confirmation" }))).toBe("done_today");
    expect(nextColumn(request({ status: "done" }))).toBeNull();
    expect(nextColumn(request({ status: "cancelled" }))).toBeNull();
  });

  test("a card moved offline goes back once the outbox has its start refused, and only then", () => {
    const moved = new Map<number, ShownMove>([
      [1, { column: "in_progress", version: 3, item: "a" }],
      [2, { column: "in_progress", version: 1, item: "b" }],
      [3, { column: "done_today", version: 2 }],
    ]);
    // waiting to be sent, or sent and gone (the board's next read ends the move): the cards stay where they were moved
    expect(refusedMoves(moved, [{ id: "a", state: "pending" }])).toEqual([]);
    expect(refusedMoves(moved, [])).toEqual([]);
    // refused when the outbox sent it: that card goes back; a move made online (no write kept) is not the outbox's to end
    expect(refusedMoves(moved, [{ id: "a", state: "conflict" }, { id: "b", state: "pending" }, { id: "c", state: "conflict" }])).toEqual([1]);
  });
});

test.describe("18: the cart's Clear queues one cancel per order", () => {
  test("a second Clear while the first cancel waits queues nothing; a refused cancel does not count", () => {
    const cancel = item({ kind: "cancel_order", subjectId: 42 });
    expect(cancelWaits([], 42)).toBe(false);
    expect(cancelWaits([cancel], 42)).toBe(true);
    expect(cancelWaits([cancel], 43)).toBe(false);
    // line 42's write is not the order's cancel
    expect(cancelWaits([item({ kind: "accept_line", subjectId: 42 })], 42)).toBe(false);
    // refused already (in the tray): Clear may queue a new one
    expect(cancelWaits([{ ...cancel, state: "conflict" }], 42)).toBe(false);
  });

  test("the cart's message for a send that could not be queued exists in both languages", () => {
    expect(en.pad.send_not_queued).toContain("Sign in");
    expect(am.pad.send_not_queued.length).toBeGreaterThan(10);
  });
});

test.describe("19: a send that may have landed did, whatever the order went on to", () => {
  test("every status after sent counts, paid and voided too; open and cancelled never", () => {
    const send = { kind: "send_existing" as const };
    expect([...SENT].sort()).toEqual(["closed", "in_progress", "paid", "ready", "sent", "served", "voided"]);
    for (const status of ORDER_STATUSES) expect(landed(send, { status }), status).toBe(!["open", "cancelled"].includes(status));
    expect(landed({ kind: "cancel_order" }, { status: "cancelled" })).toBe(true);
    expect(landed({ kind: "cancel_order" }, { status: "paid" })).toBe(false);
    expect(landed(send, null)).toBe(false);
  });
});

test.describe("20: the void dialog follows the API's void and refund rules", () => {
  test("refund when the order lists refund, void when it lists void or a payment of it was chosen, neither for the rest (ADR 0032)", () => {
    expect(voidPath({ actions: ["refund"] })).toBe("refund");
    expect(voidPath({ actions: ["void"] })).toBe("void");
    expect(voidPath({ actions: ["edit", "send", "cancel"] })).toBe("none");
    expect(voidPath({ actions: [] })).toBe("none");
    // a settled order (paid in full, not served) and a voided one list no void of their own, but a payment of theirs may still be voided
    expect(voidPath({ actions: [] }, true)).toBe("void");
    expect(voidPath({})).toBe("none");
    expect(en.till.void_none).toContain("{no}");
    expect(am.till.void_none).toContain("{no}");
  });
});

test.describe("21: the shift bar names every shift status, and the station is the screen on view's", () => {
  function shift(status: string): Shift {
    return {
      id: 7,
      status,
      started_by: { id: 11, name: "Abebe" },
      roster: [{ user: { id: 11, name: "Abebe" }, source: "started", joined_at: "2026-10-23T05:00:00Z", left_at: null }],
    } as unknown as Shift;
  }
  const crew = { canCount: true, canManage: false };

  test("only a running shift holds the station: scheduled, handed in, locked and cancelled are idle and a shift may start", () => {
    expect(barMode(shift("opening"))).toBe("opening");
    expect(barMode(shift("open"))).toBe("open");
    expect(barMode(shift("counting"))).toBe("counting");
    for (const status of ["scheduled", "pending_approval", "locked", "cancelled"]) {
      expect(barMode(shift(status)), status).toBe("idle");
      expect(barActions(shift(status), 11, crew), status).toMatchObject({ mode: "idle", start: true, startCarried: true, continueCount: false, close: false });
    }
    // before, every status but opening and open fell to "counting" and offered the count
    expect(barActions(shift("pending_approval"), 11, crew).continueCount).toBe(false);
  });

  test("a status this build does not know names no state it cannot be sure of and offers nothing", () => {
    expect(barMode(shift("archived"))).toBe("idle");
    expect(barActions(shift("archived"), 11, { canCount: true, canManage: true })).toEqual({
      mode: "idle",
      start: false,
      startCarried: false,
      join: false,
      leave: false,
      close: false,
      continueCount: false,
      cancel: false,
    });
    // a key on Object's prototype is not a status
    expect(barActions(shift("toString"), 11, crew).start).toBe(false);
  });

  test("a screen that leaves after the next one claimed the station lets go of its own claim only", () => {
    const station = useActiveStation.getState();
    const releaseOld = station.claim(3);
    expect(useActiveStation.getState().stationId).toBe(3);
    // the route change: the new screen mounts and claims before the old one's cleanup runs
    const releaseNew = station.claim(5);
    releaseOld();
    expect(useActiveStation.getState().stationId).toBe(5);
    // a release twice is harmless
    releaseOld();
    expect(useActiveStation.getState().stationId).toBe(5);
    releaseNew();
    expect(useActiveStation.getState().stationId).toBeNull();
    // the other order: the newest leaves first, the older one's station is back
    const a = station.claim(3);
    const b = station.claim(null);
    expect(useActiveStation.getState().stationId).toBeNull();
    b();
    expect(useActiveStation.getState().stationId).toBe(3);
    a();
    expect(useActiveStation.getState().stationId).toBeNull();
  });
});

test.describe("22: the kitchen display's line buttons", () => {
  test("only a line's own writes show it as sending: an order's cancel with the same id does not", () => {
    expect(LINE_KINDS).toEqual(["accept_line", "ready_line", "reject_line", "serve_line"]);
    expect(lineWaits([item({ kind: "cancel_order", subjectId: 42 })], 42)).toBe(false);
    expect(lineWaits([item({ kind: "send_existing", subjectId: 42 })], 42)).toBe(false);
    for (const kind of LINE_KINDS) expect(lineWaits([item({ kind, subjectId: 42 })], 42), kind).toBe(true);
    expect(lineWaits([item({ kind: "accept_line", subjectId: 42, state: "conflict" })], 42)).toBe(false);
  });

  test("a tap holds its line until the display reads the new status, lets go on a refusal, and never for longer than the hold", () => {
    const at = 1_000_000;
    const tap = { itemId: "w1", status: "queued", at };
    // queued, or on the wire
    expect(tapHolds(tap, [{ id: "w1", state: "pending" }], "queued", at + 100)).toBe(true);
    // accepted and gone from the queue, the line not read again yet: the second tap is held (it would be refused, the line moved on)
    expect(tapHolds(tap, [], "queued", at + 2_000)).toBe(true);
    // the display read the line accepted: the next button is free
    expect(tapHolds(tap, [], "accepted", at + 2_000)).toBe(false);
    // refused: the tray says why and the line can be tapped again
    expect(tapHolds(tap, [{ id: "w1", state: "conflict" }], "queued", at + 100)).toBe(false);
    // the hold ends whatever happens
    expect(tapHolds(tap, [], "queued", at + TAP_HOLD_MS)).toBe(false);
    expect(tapHolds(null, [], "queued", at)).toBe(false);
  });
});

test.describe("the new words exist in both languages", () => {
  test("production's half-done and refused moves", () => {
    for (const key of ["move_half_done", "move_refused"] as const) {
      expect(en.production[key]).toContain("{name}");
      expect(am.production[key]).toContain("{name}");
    }
  });
});
