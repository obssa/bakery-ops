import { expect, test } from "@playwright/test";
import { loadOutbox, memoryOutboxDb, type OutboxDb } from "../../src/lib/outbox/db";
import {
  backoffMs,
  LEGACY_STORAGE_KEY,
  migrateLegacy,
  nextSendable,
  nextWakeAt,
  outcomeOf,
  pendingCounts,
  pick,
  refuse,
  resumeAll,
  retryLater,
  screenOf,
  settle,
  type OutboxItem,
} from "../../src/lib/outbox/logic";

// Phase 5 step 5.2a, the logic under the p5-offline flow (no browser, no stack): which queued write goes next (strict order per order, an
// item after the one it depends on, then with that one's server id), how long a write waits after a failure (1, 2, 4 ... 60 s), what each
// answer does to it, the pending count per screen, and the move of the old localStorage queue into IndexedDB.

let seq = 0;
function item(overrides: Partial<OutboxItem> = {}): OutboxItem {
  seq += 1;
  return {
    id: `i${seq}`,
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
    createdAt: 1_000,
    attempts: 0,
    nextAttemptAt: 0,
    state: "pending",
    conflict: null,
    ...overrides,
  };
}

const NOW = 1_000_000;

class FakeStorage {
  constructor(public readonly values = new Map<string, string>()) {}
  getItem(key: string): string | null {
    return this.values.get(key) ?? null;
  }
  removeItem(key: string): void {
    this.values.delete(key);
  }
}

test.describe("offline logic: the outbox", () => {
  test("a write that failed waits 1, 2, 4 ... seconds, never more than 60, and keeps its Idempotency-Key", () => {
    expect([1, 2, 3, 4, 5, 6, 7, 8, 30].map(backoffMs)).toEqual([1_000, 2_000, 4_000, 8_000, 16_000, 32_000, 60_000, 60_000, 60_000]);

    const send = item({ group: "order:a" });
    const [later] = retryLater([send], send.id, NOW);
    expect(later).toMatchObject({ attempts: 1, nextAttemptAt: NOW + 1_000, idempotencyKey: send.idempotencyKey, state: "pending" });
    const [again] = retryLater([later], send.id, NOW + 1_000);
    expect(again).toMatchObject({ attempts: 2, nextAttemptAt: NOW + 3_000, idempotencyKey: send.idempotencyKey });

    // nothing goes out before its time; the timer is set for the earliest one that could
    expect(nextSendable([again], 7, NOW + 2_999)).toBeNull();
    expect(nextWakeAt([again], 7)).toBe(NOW + 3_000);
    expect(nextSendable([again], 7, NOW + 3_000)?.id).toBe(send.id);

    // the connection came back (the browser's online event): everything waiting goes now, a conflict stays where it is
    const conflict = item({ state: "conflict", conflict: { status: 409, body: {}, at: NOW, blockedBy: null }, nextAttemptAt: NOW + 50_000 });
    const resumed = resumeAll([again, conflict]);
    expect(resumed[0].nextAttemptAt).toBe(0);
    expect(resumed[1]).toEqual(conflict);
  });

  test("strict order per order: an order's writes go one after another, and another order does not wait for it", () => {
    const a1 = item({ group: "order:a" });
    const a2 = item({ group: "order:a", kind: "cancel_order" });
    const b1 = item({ group: "order:b" });
    const items = [a1, a2, b1];
    expect(nextSendable(items, 7, NOW)?.id).toBe(a1.id);

    // the head of order a waits after a failure: its next write stays behind it, order b goes
    const waiting = retryLater(items, a1.id, NOW);
    expect(nextSendable(waiting, 7, NOW)?.id).toBe(b1.id);
    const bDone = settle(waiting, b1.id, {});
    expect(nextSendable(bDone, 7, NOW)).toBeNull();
    expect(nextSendable(bDone, 7, NOW + 1_000)?.id).toBe(a1.id);
    expect(nextSendable(settle(bDone, a1.id, {}), 7, NOW)?.id).toBe(a2.id);

    // only the account that queued a write sends it
    expect(nextSendable(items, 8, NOW)).toBeNull();
    expect(nextWakeAt(items, 8)).toBeNull();
  });

  test("an item waits for the one it depends on, then goes with that one's server id", () => {
    // a line action queued on an order whose send has not reached the server yet: it needs the line's id from the send's answer
    const send = item({ group: "order:u1", subjectUuid: "u1", body: { client_uuid: "u1", lines: [{ client_uuid: "l1" }, { client_uuid: "l2" }] } });
    const serve = item({
      kind: "serve_line",
      path: "/order-lines/{dep}/served",
      dependsOn: send.id,
      needs: { token: "{dep}", from: "lines[client_uuid=l2].id" },
    });
    // a payment row that carries a file id from another item (step 5.2b's photo before its payment)
    const photo = item({ kind: "pay_batch", path: "/files", screen: "till" });
    const pay = item({
      kind: "pay_batch",
      path: "/orders/5/payments",
      body: { client_uuid: "b1", rows: [{ channel: "wallet", amount: "10.00", photo_file_id: "{dep}", reference: "x {dep}" }] },
      group: "order:5",
      dependsOn: photo.id,
      needs: { token: "{dep}", from: "id" },
      screen: "till",
    });
    const items = [send, serve, photo, pay];

    // the dependent waits whatever its group says
    expect(nextSendable([serve, send], 7, NOW)?.id).toBe(send.id);
    const failed = retryLater(items, send.id, NOW);
    expect(nextSendable(failed, 7, NOW)?.id).toBe(photo.id);
    const noPhotoYet = retryLater(failed, photo.id, NOW);
    expect(nextSendable(noPhotoYet, 7, NOW)).toBeNull();

    const sent = settle(items, send.id, { id: 40, client_uuid: "u1", lines: [{ id: 41, client_uuid: "l1" }, { id: 42, client_uuid: "l2" }] });
    const served = sent.find((i) => i.id === serve.id)!;
    expect(served).toMatchObject({ path: "/order-lines/42/served", dependsOn: null, needs: null });
    expect(sent.some((i) => i.id === send.id)).toBe(false);

    const uploaded = settle(sent, photo.id, { id: 9, purpose: "receipt" });
    const paid = uploaded.find((i) => i.id === pay.id)!;
    // a whole value takes the answer's type; inside a longer string it is written in
    expect(paid.body).toEqual({ client_uuid: "b1", rows: [{ channel: "wallet", amount: "10.00", photo_file_id: 9, reference: "x 9" }] });
    expect(paid.idempotencyKey).toBe(pay.idempotencyKey);

    // an item whose dependency is no longer queued (an old queue's "after", already sent) is free to go
    const orphan = item({ dependsOn: "gone" });
    expect(nextSendable([orphan], 7, NOW)?.id).toBe(orphan.id);
  });

  test("a refusal moves the item, and what depends on it, to the conflict list; the rest of the queue goes on", () => {
    const send = item({ group: "order:u1" });
    const after = item({ kind: "send_existing", dependsOn: send.id, group: "order:u2" });
    const next = item({ kind: "serve_line", dependsOn: after.id });
    const fix = item({ kind: "replace_lines", group: "order:u1" });
    const other = item({ group: "order:u9" });
    const refusal = { status: 409, body: { message_key: "orders.send.shortfall", message_en: "Short", message_am: "አጭር" } };

    const items = refuse([send, after, next, fix, other], send.id, refusal, NOW);
    const byId = new Map(items.map((i) => [i.id, i]));
    expect(byId.get(send.id)).toMatchObject({ state: "conflict", conflict: { status: 409, body: refusal.body, at: NOW, blockedBy: null } });
    // kept whole, with its key, for the conflict tray (step 5.2c)
    expect(byId.get(send.id)?.idempotencyKey).toBe(send.idempotencyKey);
    expect(byId.get(after.id)).toMatchObject({ state: "conflict", conflict: { status: 409, blockedBy: send.id } });
    expect(byId.get(next.id)).toMatchObject({ state: "conflict", conflict: { blockedBy: send.id } });
    expect(byId.get(other.id)?.state).toBe("pending");

    // a conflict holds nothing up: the order's later write (the waiter's fix) and the other order go
    expect(nextSendable(items, 7, NOW)?.id).toBe(fix.id);
    expect(nextSendable(settle(items, fix.id, {}), 7, NOW)?.id).toBe(other.id);
    expect(pendingCounts(items, "pad")).toEqual({ here: 2, elsewhere: 0, conflicts: 3 });
  });

  test("what each answer does to a queued write", () => {
    // the network, a server error, a timeout or a throttle: kept, same key, tried again later
    for (const status of [0, 500, 502, 503, 504, 408, 429]) expect(outcomeOf(status, "x")).toBe("retry");
    // any other refusal is for good: the conflict list
    for (const status of [400, 403, 404, 409, 410, 422]) expect(outcomeOf(status, "x")).toBe("conflict");
    expect(outcomeOf(401, "identity.acting_session.expired")).toBe("conflict");
    // the device is not enrolled, revoked or of another branch: kept, and nothing more is sent until the page starts again (step 5.1b)
    for (const key of ["identity.device.unknown", "identity.device.revoked", "identity.device.other_branch"]) expect(outcomeOf(403, key)).toBe("device");
    // the account's session ended: kept for when it signs in again
    expect(outcomeOf(401, "auth.unauthenticated")).toBe("signed_out");
  });

  test("the pending count is per screen", () => {
    expect(screenOf("/app/en/pad/")).toBe("pad");
    expect(screenOf("/app/am/till/")).toBe("till");
    expect(screenOf("/app/en/kds/kitchen/")).toBe("kds_kitchen");
    expect(screenOf("/en/kds/bar")).toBe("kds_bar");
    expect(screenOf("/app/en/production/")).toBe("production");
    expect(screenOf("/app/en/shifts/count/")).toBe("shifts");
    expect(screenOf("/app/en/admin/catalog/")).toBe("admin");
    expect(screenOf("/app/en/")).toBe("other");
    expect(screenOf("")).toBe("other");

    const items = [item({ screen: "pad" }), item({ screen: "pad" }), item({ screen: "till" }), item({ screen: null })];
    expect(pendingCounts(items, "pad")).toEqual({ here: 2, elsewhere: 2, conflicts: 0 });
    expect(pendingCounts(items, "till")).toEqual({ here: 1, elsewhere: 3, conflicts: 0 });
    expect(pendingCounts([], "pad")).toEqual({ here: 0, elsewhere: 0, conflicts: 0 });
  });

  test("an answer's value is found by a short path", () => {
    const answer = { id: 3, batch: { id: 12 }, lines: [{ id: 1, client_uuid: "a" }, { id: 2, client_uuid: "b" }] };
    expect(pick(answer, "id")).toBe(3);
    expect(pick(answer, "batch.id")).toBe(12);
    expect(pick(answer, "lines[client_uuid=b].id")).toBe(2);
    expect(pick(answer, "lines[client_uuid=c].id")).toBeUndefined();
    expect(pick(null, "id")).toBeUndefined();
  });

  test("the old localStorage queue (v1) becomes outbox items: its order kept, 'after' as the dependency, the order as the group", () => {
    const v1 = [
      { id: "s", kind: "send_order", method: "POST", path: "/orders", body: { client_uuid: "u1" }, idempotencyKey: "k1", subjectUuid: "u1", inline: true, userId: 7, subjectId: null, createdAt: 5, attempts: 2, headers: { "X-Acting-Session": "a" } },
      { id: "r", kind: "replace_lines", method: "PUT", path: "/orders/3/lines", body: {}, idempotencyKey: "k2", subjectUuid: "u2", userId: 7, subjectId: 3, createdAt: 5, attempts: 0 },
      { id: "e", kind: "send_existing", method: "POST", path: "/orders/3/send", body: {}, idempotencyKey: "k3", subjectUuid: "u2", after: "r", userId: 7, subjectId: 3, createdAt: 6, attempts: 0 },
      { id: "a", kind: "accept_line", method: "POST", path: "/order-lines/9/accept", body: {}, idempotencyKey: "k4", userId: 7, subjectId: 9, createdAt: 7, attempts: 0 },
      { id: "bad", kind: "send_order" },
      null,
    ];
    const items = migrateLegacy(JSON.stringify(v1));
    expect(items.map((i) => i.id)).toEqual(["s", "r", "e", "a"]);
    expect(items.map((i) => i.seq)).toEqual([...items.map((i) => i.seq)].sort((x, y) => x - y));
    expect(items[0]).toMatchObject({
      kind: "send_order",
      idempotencyKey: "k1",
      headers: { "X-Acting-Session": "a" },
      group: "order:u1",
      dependsOn: null,
      inline: true,
      screen: "pad",
      state: "pending",
      conflict: null,
      nextAttemptAt: 0,
      attempts: 2,
    });
    expect(items[2]).toMatchObject({ dependsOn: "r", group: "order:u2", inline: false });
    expect(items[1].group).toBe(items[2].group);
    // a kitchen or bar action: which display queued it is not known any more
    expect(items[3]).toMatchObject({ group: "line:9", screen: null });

    expect(migrateLegacy(null)).toEqual([]);
    expect(migrateLegacy("not json")).toEqual([]);
    expect(migrateLegacy(JSON.stringify({ items: [] }))).toEqual([]);
  });

  test("the first start moves the old queue into IndexedDB and clears it; a failed move keeps it", async () => {
    const legacy = JSON.stringify([
      { id: "s", kind: "send_order", method: "POST", path: "/orders", body: {}, idempotencyKey: "k1", subjectUuid: "u1", userId: 7, subjectId: null, createdAt: 5, attempts: 0 },
    ]);
    const kept = item({ id: "already", seq: 0 });
    const db = memoryOutboxDb([kept]);
    const storage = new FakeStorage(new Map([[LEGACY_STORAGE_KEY, legacy]]));

    const first = await loadOutbox(db, storage);
    expect(first.durable).toBe(true);
    expect(first.items.map((i) => i.id)).toEqual(["already", "s"]);
    expect((await db.all()).map((i) => i.id).sort()).toEqual(["already", "s"]);
    expect(storage.getItem(LEGACY_STORAGE_KEY)).toBeNull();

    // the next start finds nothing to move and the same queue
    const second = await loadOutbox(db, storage);
    expect(second.items.map((i) => i.id)).toEqual(["already", "s"]);

    // a queue already moved (a crash after the write, before the clear) is not doubled
    storage.values.set(LEGACY_STORAGE_KEY, legacy);
    expect((await loadOutbox(db, storage)).items.map((i) => i.id)).toEqual(["already", "s"]);

    // IndexedDB refused the write: the items still go out from this tab, and the old queue stays for the next start
    const broken: OutboxDb = { ...memoryOutboxDb(), replaceAll: async () => Promise.reject(new Error("quota")) };
    const blocked = new FakeStorage(new Map([[LEGACY_STORAGE_KEY, legacy]]));
    const fallback = await loadOutbox(broken, blocked);
    expect(fallback.items.map((i) => i.id)).toEqual(["s"]);
    expect(fallback.durable).toBe(false);
    expect(blocked.getItem(LEGACY_STORAGE_KEY)).toBe(legacy);

    // no IndexedDB at all (a private window): the same, in memory
    const none = await loadOutbox(null, new FakeStorage(new Map([[LEGACY_STORAGE_KEY, legacy]])));
    expect(none).toMatchObject({ durable: false });
    expect(none.items.map((i) => i.id)).toEqual(["s"]);
  });
});
