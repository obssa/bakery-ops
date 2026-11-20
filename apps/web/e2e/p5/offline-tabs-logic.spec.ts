import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { ACTING_HEADER, expiryLocks } from "../../src/lib/acting";
import { baseHeaders } from "../../src/lib/api/client";
import { restoresInto } from "../../src/lib/cart";
import { diffQueue, memoryOutboxDb, mergeQueue, savedOf, type OutboxDb, type Saved } from "../../src/lib/outbox/db";
import { ACTING_SESSION_HEADER, actingSessionOf, cartOwnerOf, retryLater, settle, type OutboxItem } from "../../src/lib/outbox/logic";
import { STATION_HEADER, useActiveStation } from "../../src/lib/station";

// ADR 0029, the logic under the p5-offline two-tab flow (no browser, no stack):
// - two tabs of one tablet over one store: each writes only what it changed, so neither erases the other's writes, and each folds in what
//   the other wrote (a write sent there leaves here; a write queued here and not written yet stays);
// - a queued write goes with the station it was queued at, not the one on screen when it is sent;
// - a refused write of waiter A (A's acting session ended) neither locks waiter B's pad nor fills B's cart.

let seq = 0;
function item(overrides: Partial<OutboxItem> = {}): OutboxItem {
  seq += 1;
  return {
    id: `t${seq}`,
    seq,
    kind: "send_order",
    method: "POST",
    path: "/orders",
    body: {},
    idempotencyKey: `key-${seq}`,
    subjectId: null,
    subjectUuid: null,
    group: null,
    dependsOn: null,
    needs: null,
    inline: false,
    userId: 7,
    screen: "pad",
    createdAt: 1_000 + seq,
    attempts: 0,
    nextAttemptAt: 0,
    state: "pending",
    conflict: null,
    ...overrides,
  };
}

/** One tab as the store keeps it: its queue in memory, and what it last wrote to or read from the shared store. */
class Tab {
  queue: OutboxItem[] = [];
  saved: Saved = new Map();
  constructor(private readonly db: OutboxDb) {}

  async load() {
    this.queue = await this.db.all();
    this.saved = savedOf(this.queue);
  }

  async write() {
    const { puts, deletes } = diffQueue(this.saved, this.queue);
    await this.db.apply(puts, deletes);
    this.saved = savedOf(this.queue);
  }

  async sync() {
    const stored = await this.db.all();
    this.queue = mergeQueue(this.saved, this.queue, stored);
    this.saved = savedOf(stored);
    await this.write();
  }

  ids() {
    return this.queue.map((i) => i.id);
  }
}

test.describe("ADR 0029: the tabs of one tablet share one outbox", () => {
  test("two tabs that queue offline both keep their writes in the store (a tab never clears the store)", async () => {
    const db = memoryOutboxDb();
    const pad = new Tab(db);
    const till = new Tab(db);
    await pad.load();
    await till.load();

    const order = item({ id: "pad-order" });
    pad.queue = [...pad.queue, order];
    await pad.write();
    // the till never read the pad's write: before ADR 0029 its write was a clear and a put, and the pad's order was gone
    const payment = item({ id: "till-payment", kind: "pay_batch" });
    till.queue = [...till.queue, payment];
    await till.write();
    expect((await db.all()).map((i) => i.id)).toEqual(["pad-order", "till-payment"]);

    // each folds in the other's write
    await pad.sync();
    await till.sync();
    expect(pad.ids()).toEqual(["pad-order", "till-payment"]);
    expect(till.ids()).toEqual(["pad-order", "till-payment"]);
  });

  test("a write sent by one tab leaves the other; a write queued and not written yet stays; one dropped here stays dropped", async () => {
    const first = item({ id: "a" });
    const second = item({ id: "b" });
    const db = memoryOutboxDb([first, second]);
    const one = new Tab(db);
    const two = new Tab(db);
    await one.load();
    await two.load();

    // tab one sends "a" (settled: it leaves its queue) and writes that
    one.queue = settle(one.queue, "a", { id: 1 });
    await one.write();
    // tab two meanwhile queued "c" and has not written it, and changed "b" (a failed attempt waits)
    const third = item({ id: "c" });
    two.queue = [...retryLater(two.queue, "b", 5_000), third];
    const waiting = two.queue.find((i) => i.id === "b")!;

    await two.sync();
    expect(two.ids()).toEqual(["b", "c"]);
    // its own change to "b" is kept, and now written
    expect(two.queue.find((i) => i.id === "b")).toBe(waiting);
    expect((await db.all()).find((i) => i.id === "b")?.attempts).toBe(1);
    expect((await db.all()).map((i) => i.id)).toEqual(["b", "c"]);

    // tab one takes "c" in, then discards it: the store's copy does not bring it back, and the discard reaches the store and tab two
    await one.sync();
    expect(one.ids()).toEqual(["b", "c"]);
    one.queue = one.queue.filter((i) => i.id !== "c");
    await one.sync();
    expect(one.ids()).toEqual(["b"]);
    expect((await db.all()).map((i) => i.id)).toEqual(["b"]);
    await two.sync();
    expect(two.ids()).toEqual(["b"]);
  });

  test("a write that another tab refused comes in as that tab wrote it", async () => {
    const queued = item({ id: "send" });
    const db = memoryOutboxDb([queued]);
    const sender = new Tab(db);
    const other = new Tab(db);
    await sender.load();
    await other.load();
    sender.queue = sender.queue.map((i) => ({ ...i, state: "conflict" as const, conflict: { status: 409, body: { message_key: "orders.send.shortfall" }, at: 9, blockedBy: null } }));
    await sender.write();
    await other.sync();
    expect(other.queue).toHaveLength(1);
    expect(other.queue[0]).toMatchObject({ id: "send", state: "conflict" });
  });

  test("diffQueue writes only the records that changed, and everything after the connection was opened again", () => {
    const a = item();
    const b = item();
    const saved = savedOf([a, b]);
    const c = item();
    const changed = { ...b, attempts: 1 };
    expect(diffQueue(saved, [a, changed, c])).toEqual({ puts: [changed, c], deletes: [] });
    expect(diffQueue(saved, [c])).toEqual({ puts: [c], deletes: [a.id, b.id] });
    expect(diffQueue(saved, [a, b], true).puts).toEqual([a, b]);
  });
});

test.describe("ADR 0029: a queued write keeps the station it was queued at", () => {
  let release: (() => void) | null = null;
  test.afterEach(() => {
    release?.();
    release = null;
  });

  test("the station fixed by the caller wins over the one picked now; none fixed sends the one picked now", () => {
    release = useActiveStation.getState().claim(5);
    expect(baseHeaders("application/json", undefined, "dev-1")[STATION_HEADER]).toBe("5");
    // a payment queued on the till (station 3) and sent from the kitchen display (station 5)
    expect(baseHeaders("application/json", undefined, "dev-1", 3)[STATION_HEADER]).toBe("3");
    // queued where no station was picked: it goes without one
    expect(baseHeaders("application/json", undefined, "dev-1", null)).not.toHaveProperty(STATION_HEADER);
    // the acting session the write carries rides along; the device id is always this device's own
    const headers = baseHeaders("application/json", { [ACTING_HEADER]: "12", "X-Device-Id": "spoofed" }, "dev-1", 3);
    expect(headers).toMatchObject({ [ACTING_HEADER]: "12", [STATION_HEADER]: "3", "X-Device-Id": "dev-1" });
  });
});

test.describe("ADR 0029: one waiter's refused write stays with that waiter", () => {
  test("an ended acting session locks the pad only when it is the session of the waiter who has the pad now", () => {
    expect(ACTING_HEADER).toBe(ACTING_SESSION_HEADER);
    expect(actingSessionOf(item({ headers: { [ACTING_SESSION_HEADER]: "41" } }))).toBe(41);
    expect(actingSessionOf(item())).toBeNull();
    // a call made now (no session named) locks, as before
    expect(expiryLocks(42, undefined)).toBe(true);
    // waiter A's queued write (session 41) refused while waiter B (session 42) has the pad: B stays
    expect(expiryLocks(42, 41)).toBe(false);
    // B's own queued write: B's pad locks
    expect(expiryLocks(42, 42)).toBe(true);
    // a write without an acting session never locks a waiter out
    expect(expiryLocks(42, null)).toBe(false);
  });

  test("Edit and resend gives the order back to the cart of the waiter it was queued for, and only to it", () => {
    const padWrite = item({ userId: 7, actor: { id: 30, name: "Waiter A" }, headers: { [ACTING_SESSION_HEADER]: "41" } });
    const owner = cartOwnerOf(padWrite);
    expect(owner).toEqual({ id: 30, name: "Waiter A" });
    expect(restoresInto({ owner }, 30)).toBe(true);
    expect(restoresInto({ owner }, 31)).toBe(false);
    // the till: the signed-in cashier's own cart
    const tillWrite = item({ userId: 9, actor: null });
    expect(cartOwnerOf(tillWrite)).toEqual({ id: 9, name: null });
    expect(restoresInto({ owner: cartOwnerOf(tillWrite) }, 9)).toBe(true);
    // a write queued before the waiter was kept: the cart that opens next takes it, as before
    expect(cartOwnerOf(item())).toBeUndefined();
    expect(restoresInto({}, 31)).toBe(true);
  });

  test("the pad says whose order waits, and the shell says when the queue could not be saved, in both languages", () => {
    for (const messages of [en, am]) {
      expect(messages.pad.restore_other).toContain("{name}");
      expect(messages.pad.restore_other_unnamed.length).toBeGreaterThan(10);
      expect(messages.outbox.not_durable.length).toBeGreaterThan(10);
    }
    expect(am.pad.restore_other).toMatch(/[ሀ-፿]/);
    expect(am.outbox.not_durable).toMatch(/[ሀ-፿]/);
  });
});
