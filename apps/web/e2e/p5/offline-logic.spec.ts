import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import type { PaymentChannel } from "../../src/lib/api/types";
import { DB_VERSION, loadOutbox, memoryOutboxDb, memoryPhotoDb, OUTBOX_STORE, PHOTO_STORE, upgradeDb, type OutboxDb } from "../../src/lib/outbox/db";
import {
  ABANDONED_AFTER_MS,
  abandonedPhotos,
  backoffMs,
  LEGACY_STORAGE_KEY,
  LocalRefusal,
  migrateLegacy,
  nextSendable,
  nextWakeAt,
  outcomeOf,
  pendingCounts,
  photoNeeds,
  PHOTO_QUEUE_MB,
  photoQueueLimit,
  photoQuota,
  photosOf,
  photoToken,
  pick,
  refuse,
  resumeAll,
  retryLater,
  screenOf,
  sendPhotos,
  settle,
  type OutboxItem,
  type PhotoRecord,
} from "../../src/lib/outbox/logic";
import { batchRows, keptPhotos, refusedPhoto, rowIssue, type PayRow } from "../../src/lib/paySheet";
import { cartFrom } from "../../src/lib/cart";
import { actionsFor, CART_KINDS, cartSourceOf, discardEntry, landed, needsLookup, reasonOf, resendEntry, supersede, trayEntries } from "../../src/lib/outbox/tray";
import { afterGap, RECONNECT_GAP_MS } from "../../src/lib/reconnect";

// Phase 5 step 5.2a, the logic under the p5-offline flow (no browser, no stack): which queued write goes next (strict order per order, an
// item after the one it depends on, then with that one's server id), how long a write waits after a failure (1, 2, 4 ... 60 s), what each
// answer does to it, the pending count per screen, and the move of the old localStorage queue into IndexedDB.
// Step 5.2b, the photo queue: a payment after the upload of its photos (each file id written into its row), the quota of photos waiting on
// the tablet, the photos dropped once nothing needs them, the upload that skips what is already up, and the database's version 2.

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

const MB = 1024 * 1024;
const HOUR = 3_600_000;

const WALLET: PaymentChannel = {
  id: 1,
  code: "wallet",
  name_en: "Wallet",
  name_am: "ዋሌት",
  is_enabled: true,
  requires_photo: true,
  requires_reference: true,
  sort: 1,
  accepted_now: true,
  reference_pattern: null,
  reference_hint_en: null,
  reference_hint_am: null,
  photo_hint_en: null,
  photo_hint_am: null,
  is_cash: false,
  deleted_at: null,
};

function walletRow(id: string, photo: PayRow["photo"]): PayRow {
  return { id, channel: "wallet", amount: "50.00", tendered: "", reference: "TX123456", photo };
}

function photoRecord(clientUuid: string, overrides: Partial<PhotoRecord> = {}): PhotoRecord {
  return {
    clientUuid,
    key: `k-${clientUuid}`,
    purpose: "receipt",
    blob: new Blob([new Uint8Array(16)], { type: "image/jpeg" }),
    bytes: 16,
    userId: 7,
    createdAt: NOW,
    fileId: null,
    ...overrides,
  };
}

test.describe("offline logic: the photo queue (step 5.2b)", () => {
  test("a payment waits for the upload of its photos, then goes with each photo's file id; a refused photo holds it back", () => {
    const rows = [
      walletRow("r1", { status: "kept", fileId: null, clientUuid: "p1", key: "k1" }),
      walletRow("r2", { status: "uploaded", fileId: 31, clientUuid: "p2", key: "k2" }),
      walletRow("r3", { status: "kept", fileId: null, clientUuid: "p3", key: "k3" }),
    ];
    // a photo kept on the tablet completes its row: Complete no longer waits for an upload
    expect(rows.map((row) => rowIssue(row, WALLET))).toEqual([null, null, null]);
    expect(rowIssue(walletRow("r4", { status: "kept", fileId: null, clientUuid: null, key: null }), WALLET)).toBe("photo_missing");
    expect(rowIssue(walletRow("r5", { status: "uploading", fileId: null, clientUuid: "p5", key: "k5" }), WALLET)).toBe("photo_uploading");

    // the photos to upload first, and the batch's rows with a token where a file id will be
    const kept = keptPhotos(rows, [WALLET]);
    expect(kept).toEqual(["p1", "p3"]);
    expect(photoToken("p1")).not.toBe(photoToken("p3"));
    const body = { client_uuid: "b1", rows: batchRows(rows, [WALLET]) };
    expect(body.rows.map((r) => r.receipt_file_id)).toEqual([photoToken("p1"), 31, photoToken("p3")]);

    const upload = item({ kind: "upload_photos", path: "/files", body: { photos: kept }, screen: "till" });
    const pay = item({
      kind: "pay_batch",
      path: "/orders/5/payment-batches",
      body,
      group: "order:o5",
      dependsOn: upload.id,
      needs: photoNeeds(kept),
      screen: "till",
    });
    const queue = [upload, pay];
    expect(photosOf(upload)).toEqual(["p1", "p3"]);
    expect(photosOf(pay)).toEqual([]);
    expect(nextSendable(queue, 7, NOW)?.id).toBe(upload.id);
    // the upload failed on the network: the payment does not go without its photos
    expect(nextSendable(retryLater(queue, upload.id, NOW), 7, NOW)).toBeNull();

    // the photos are up: the payment goes, the same write (its key), with each file id where its token was
    const uploaded = settle(queue, upload.id, { files: [{ client_uuid: "p1", id: 41 }, { client_uuid: "p3", id: 43 }] });
    expect(uploaded).toHaveLength(1);
    expect(uploaded[0]).toMatchObject({ id: pay.id, dependsOn: null, needs: null, idempotencyKey: pay.idempotencyKey, state: "pending" });
    expect((uploaded[0].body as typeof body).rows.map((r) => r.receipt_file_id)).toEqual([41, 31, 43]);
    expect(nextSendable(uploaded, 7, NOW)?.id).toBe(pay.id);

    // an answer without one of the photos makes the payment a conflict, never a payment without its photo
    const partial = settle(queue, upload.id, { files: [{ client_uuid: "p1", id: 41 }] });
    expect(partial[0]).toMatchObject({ state: "conflict", conflict: { blockedBy: upload.id, body: { message_key: "outbox.dependency_answer_missing" } } });

    // a refused photo (not an image, too large) holds the payment back as a conflict, and names the photo
    const refused = refuse(queue, upload.id, { status: 422, body: { message_key: "files.not_an_image", photo_client_uuid: "p3" } }, NOW);
    expect(refused.map((i) => [i.state, i.conflict?.blockedBy ?? null])).toEqual([
      ["conflict", null],
      ["conflict", upload.id],
    ]);
    expect(refusedPhoto({ status: 422, body: refused[1].conflict?.body })).toBe("p3");
    expect(refusedPhoto({ status: 422, body: { message_key: "payments.batch.sum" } })).toBeNull();
    expect(refusedPhoto(new Error("x"))).toBeNull();

    // the same upload still running on the server (a slow line, a second tab) is tried again with its key, not refused
    expect(outcomeOf(409, "idempotency.in_flight")).toBe("retry");
    expect(outcomeOf(409, "payments.batch.sum")).toBe("conflict");
  });

  test("the upload sends each photo kept on the tablet with its own client_uuid and key, once: a retry skips what is already up", async () => {
    const photos = memoryPhotoDb();
    await photos.putPhoto(photoRecord("p1"));
    await photos.putPhoto(photoRecord("p2"));
    const sent: string[] = [];
    let down = true;
    const upload = async (photo: PhotoRecord) => {
      sent.push(`${photo.clientUuid} ${photo.key}`);
      if (photo.clientUuid === "p2" && down) throw new Error("network");
      return { id: photo.clientUuid === "p1" ? 41 : 42 };
    };

    await expect(sendPhotos(["p1", "p2"], photos, upload)).rejects.toThrow("network");
    // the first is up: its file id is kept with it, and it no longer waits
    expect((await photos.photo("p1"))?.fileId).toBe(41);
    expect((await photos.photo("p2"))?.fileId).toBeNull();

    down = false;
    expect(await sendPhotos(["p1", "p2"], photos, upload)).toEqual({
      files: [
        { client_uuid: "p1", id: 41 },
        { client_uuid: "p2", id: 42 },
      ],
    });
    expect(sent).toEqual(["p1 k-p1", "p2 k-p2", "p2 k-p2"]);

    // a photo no longer on the tablet is refused here, named, in both languages: its payment becomes a conflict instead of going without it
    const missing = await sendPhotos(["gone"], photos, upload).catch((error: unknown) => error);
    expect(missing).toBeInstanceOf(LocalRefusal);
    const refusal = missing as LocalRefusal;
    expect(refusal.body).toMatchObject({ message_key: "outbox.photo_missing", photo_client_uuid: "gone" });
    expect(refusal.body.message_en).toBeTruthy();
    expect(refusal.body.message_am).toBeTruthy();
    expect(refusal.body.message_am).not.toBe(refusal.body.message_en);
  });

  test("the quota: a new photo is refused when the photos waiting would pass NEXT_PUBLIC_PHOTO_QUEUE_MB (50 by default), in both languages", () => {
    expect(PHOTO_QUEUE_MB).toBe(50);
    expect(photoQueueLimit(undefined)).toBe(50 * MB);
    expect(photoQueueLimit("")).toBe(50 * MB);
    expect(photoQueueLimit("20")).toBe(20 * MB);
    expect(photoQueueLimit("0.5")).toBe(0.5 * MB);
    for (const bad of ["0", "-3", "lots", "Infinity"]) expect(photoQueueLimit(bad)).toBe(50 * MB);

    const limit = 50 * MB;
    const waiting = (bytes: number, fileId: number | null = null) => ({ bytes, fileId });
    expect(photoQuota([], 400_000, limit)).toEqual({ allowed: true, waitingBytes: 0, limitBytes: limit });
    expect(photoQuota([waiting(30 * MB), waiting(19 * MB)], 400_000, limit)).toEqual({ allowed: true, waitingBytes: 49 * MB, limitBytes: limit });
    expect(photoQuota([waiting(30 * MB), waiting(19.8 * MB)], 400_000, limit).allowed).toBe(false);
    // a photo already up (its file id known) waits for nothing and does not count
    expect(photoQuota([waiting(30 * MB), waiting(19.8 * MB, 77)], 400_000, limit)).toEqual({ allowed: true, waitingBytes: 30 * MB, limitBytes: limit });
    // up to the limit is allowed, one byte more is not
    expect(photoQuota([waiting(limit - 10)], 10, limit).allowed).toBe(true);
    expect(photoQuota([waiting(limit - 10)], 11, limit).allowed).toBe(false);

    // the till says so in both languages, with how much waits and the limit
    for (const messages of [en, am]) {
      const text = (messages.till as Record<string, string>).photo_quota;
      expect(text).toContain("{waiting}");
      expect(text).toContain("{limit}");
    }
    expect(am.till.photo_quota).not.toBe(en.till.photo_quota);
  });

  test("a photo whose payment was abandoned is dropped; one a queued write or an open pay sheet still needs is kept", () => {
    expect(ABANDONED_AFTER_MS).toBe(12 * HOUR);
    const photos = [
      { clientUuid: "queued", createdAt: NOW - 20 * HOUR },
      { clientUuid: "refused", createdAt: NOW - 20 * HOUR },
      { clientUuid: "held", createdAt: NOW - 20 * HOUR },
      { clientUuid: "dismissed", createdAt: NOW - 60_000 },
      { clientUuid: "left", createdAt: NOW - 13 * HOUR },
      { clientUuid: "recent", createdAt: NOW - HOUR },
    ];
    const queue = [
      item({ kind: "upload_photos", path: "/files", body: { photos: ["queued"] } }),
      item({
        kind: "upload_photos",
        path: "/files",
        body: { photos: ["refused"] },
        state: "conflict",
        conflict: { status: 422, body: { message_key: "files.not_an_image" }, at: NOW, blockedBy: null },
      }),
      item({ kind: "pay_batch", path: "/orders/5/payment-batches", body: { rows: [{ receipt_file_id: photoToken("recent") }] } }),
    ];
    const held = new Set(["held"]);

    // at start nothing was let go of: only a photo nothing needs, taken over 12 hours ago (a sheet a reload or a closed tab left behind)
    expect(abandonedPhotos(photos, queue, held, new Set(), NOW)).toEqual(["left"]);
    // a write dismissed, a row's photo replaced or removed: dropped at once, unless a queued write or an open sheet still needs it
    expect(abandonedPhotos(photos, queue, held, new Set(["dismissed", "queued", "refused", "held"]), NOW)).toEqual(["dismissed", "left"]);
    // nothing queued and no sheet open: everything let go of goes
    expect(abandonedPhotos(photos, [], new Set(), new Set(photos.map((p) => p.clientUuid)), NOW)).toHaveLength(photos.length);
  });

  test("the database's version 2 adds the photo store and keeps the outbox store and the writes in it", async () => {
    class FakeDb {
      readonly stores = new Map<string, Map<string, unknown>>();
      readonly objectStoreNames = { contains: (name: string) => this.stores.has(name) };
      createObjectStore(name: string, options: { keyPath: string }) {
        if (this.stores.has(name)) throw new Error(`ConstraintError: ${name} exists`);
        this.stores.set(name, new Map());
        return { name, keyPath: options.keyPath };
      }
    }
    expect(DB_VERSION).toBe(2);
    expect(OUTBOX_STORE).toBe("outbox");
    expect(PHOTO_STORE).toBe("photos");

    // a tablet that ran step 5.2a: version 1, a write waiting in the outbox
    const tablet = new FakeDb();
    const outbox = new Map<string, unknown>([["w1", item({ id: "w1" })]]);
    tablet.stores.set(OUTBOX_STORE, outbox);
    expect(upgradeDb(tablet)).toEqual([PHOTO_STORE]);
    expect(tablet.stores.get(OUTBOX_STORE)).toBe(outbox);
    expect([...outbox.keys()]).toEqual(["w1"]);

    // a new tablet gets both; one already at version 2 gets nothing
    const fresh = new FakeDb();
    expect(upgradeDb(fresh)).toEqual([OUTBOX_STORE, PHOTO_STORE]);
    expect(upgradeDb(fresh)).toEqual([]);

    // the photo store keeps a photo by its client_uuid until it is dropped
    const photos = memoryPhotoDb();
    await photos.putPhoto(photoRecord("a"));
    await photos.putPhoto(photoRecord("b", { fileId: 9 }));
    expect((await photos.photos()).map((p) => p.clientUuid).sort()).toEqual(["a", "b"]);
    await photos.dropPhotos(["a", "missing"]);
    expect((await photos.photos()).map((p) => [p.clientUuid, p.fileId])).toEqual([["b", 9]]);
    expect(await photos.photo("a")).toBeNull();
  });
});

// Step 5.2c, the conflict tray and reconnect reconcile: every write refused on sync is an entry with the server's message in both
// languages, what was tried, and the actions the refusal allows (edit and resend, send again as a new write, discard); a new send of the
// same order from the cart replaces its refused writes; an order write whose answer was lost is looked up; the pad's cart comes back
// from a refused send; a page reconciles on the online event and when it becomes visible after a gap.
test.describe("offline logic: the conflict tray (step 5.2c)", () => {
  const shortfallBody = {
    message_key: "orders.send.shortfall",
    message_en: "Some lines cannot be made with the stock on hand.",
    message_am: "አንዳንድ መስመሮች ባለው ክምችት ሊሠሩ አይችሉም።",
    order: {
      id: 41,
      client_uuid: "order-1",
      type: "dine_in",
      dining_table: { id: 3, code: "T3", name_en: "T3", name_am: "T3" },
      notes: null,
      status: "open",
      lines: [{ id: 90, client_uuid: "line-1", item: { id: 5, name_en: "Cake", name_am: "ኬክ" }, qty: "2.0000", modifiers: [{ id: 8, name_en: "Big", name_am: "ትልቅ", price_delta: "5.00" }], notes: "warm" }],
    },
    shortfall: [{ line_client_uuid: "line-1", message_en: "Cannot add Cake ×2: Flour short by 100 g.", message_am: "ኬክ ×2 መጨመር አይቻልም፦ ዱቄት 100 g ጎድሏል።" }],
  };
  const refused = (overrides: Partial<OutboxItem>, body: Record<string, unknown>, status = 409, blockedBy: string | null = null) =>
    item({ ...overrides, state: "conflict", conflict: { status, body, at: NOW, blockedBy } });

  test("each refusal is one entry: the server's message in both languages, what was tried, why, and what may be done", () => {
    const send = refused({ kind: "send_order", subjectUuid: "order-1", inline: true, body: { client_uuid: "order-1", send: true, lines: [] } }, shortfallBody);
    const ready = refused({ kind: "ready_line", subjectId: 90 }, { message_key: "inventory.shift.required", message_en: "No shift is open.", message_am: "ፈረቃ አልተከፈተም።" });
    const pay = refused({ kind: "pay_batch", subjectId: 41 }, { message_key: "payments.shift.required", message_en: "Start your shift.", message_am: "ፈረቃዎን ይጀምሩ።" }, 422);
    const stock = refused({ kind: "finish_batch" }, { message_key: "inventory.stock.insufficient", message_en: "Not enough.", message_am: "በቂ የለም።" });
    const closed = refused({ kind: "accept_line", subjectId: 91 }, { message_key: "state_machine.illegal_transition", message_en: "Not allowed.", message_am: "አይፈቀድም።" });
    const gone = refused({ kind: "send_existing", subjectId: 41, subjectUuid: "order-2" }, { message_key: "orders.order.not_open", message_en: "No longer open.", message_am: "ክፍት አይደለም።" });
    const missing = { message_key: "outbox.photo_missing", message_en: "The photo is gone.", message_am: "ፎቶው የለም።" };
    const upload = refused({ kind: "upload_photos", body: { photos: ["p1"] } }, missing, 0);
    const batch = refused({ kind: "pay_batch", dependsOn: upload.id, subjectId: 42 }, missing, 0, upload.id);
    const lost = refused({ kind: "pay_batch", subjectId: 43 }, { message_key: "outbox.dependency_answer_missing", message_en: "Saved without it.", message_am: "ያለ እሱ ተቀምጧል።" }, 0);
    const pending = item({ kind: "serve_line", subjectId: 92 });

    const entries = trayEntries([send, ready, pay, stock, closed, gone, upload, batch, lost, pending]);
    // the batch held back by its photo's upload is shown with that upload, not on its own; a pending write is not in the tray
    expect(entries.map((e) => e.item.id)).toEqual([send.id, ready.id, pay.id, stock.id, closed.id, gone.id, upload.id, lost.id]);
    const of = (id: string) => entries.find((e) => e.item.id === id)!;

    expect(of(send.id)).toMatchObject({
      reason: "shortfall",
      status: 409,
      key: "orders.send.shortfall",
      message: { en: shortfallBody.message_en, am: shortfallBody.message_am },
      details: [{ en: shortfallBody.shortfall[0].message_en, am: shortfallBody.shortfall[0].message_am }],
      actions: ["edit", "discard"],
      held: [],
    });
    expect(of(ready.id)).toMatchObject({ reason: "shift", actions: ["resend", "discard"] });
    expect(of(pay.id)).toMatchObject({ reason: "shift", actions: ["resend", "discard"] });
    expect(of(stock.id)).toMatchObject({ reason: "stock", actions: ["resend", "discard"] });
    // a closed order: sending it again would be refused again
    expect(of(closed.id)).toMatchObject({ reason: "closed", actions: ["discard"] });
    expect(of(gone.id)).toMatchObject({ reason: "closed", actions: ["discard"] });
    // refused on the tablet (status 0) with its own message: the photo it names, and the batch it held back
    expect(of(upload.id)).toMatchObject({ reason: "photo", status: 0, photo: "p1", message: { en: missing.message_en, am: missing.message_am }, actions: ["discard"] });
    expect(of(upload.id).held.map((h) => h.id)).toEqual([batch.id]);
    expect(of(lost.id)).toMatchObject({ reason: "dependency", actions: ["discard"] });

    // a held write whose refused write is no longer there is an entry of its own
    expect(trayEntries([batch]).map((e) => e.item.id)).toEqual([batch.id]);
  });

  test("why a write was refused, from the API's key (and a photo the refusal names)", () => {
    const reason = (key: string, status = 409, extra: Record<string, unknown> = {}) => reasonOf({ status, body: { message_key: key, ...extra } });
    expect(reason("orders.send.shortfall")).toBe("shortfall");
    expect(reason("inventory.stock.insufficient")).toBe("stock");
    expect(reason("inventory.shift.required")).toBe("shift");
    expect(reason("payments.shift.required", 422)).toBe("shift");
    for (const key of ["orders.order.not_open", "orders.order.paid", "payments.order.not_payable", "state_machine.illegal_transition"]) expect(reason(key)).toBe("closed");
    expect(reason("outbox.photo_missing", 0)).toBe("photo");
    expect(reason("validation.failed", 422, { photo_client_uuid: "p1" })).toBe("photo");
    expect(reason("outbox.dependency_answer_missing", 0)).toBe("dependency");
    expect(reason("identity.acting_session.expired", 401)).toBe("session");
    expect(reason("orders.item.not_sellable", 422)).toBe("other");

    // the pad's own sends go back to the cart to be changed; any other write is sent again only when its cause can be put right
    expect(CART_KINDS).toEqual(["send_order", "replace_lines", "send_existing"]);
    for (const kind of CART_KINDS) for (const why of ["shortfall", "stock", "shift", "session", "other"] as const) expect(actionsFor(kind, why)).toEqual(["edit", "discard"]);
    for (const kind of CART_KINDS) expect(actionsFor(kind, "closed")).toEqual(["discard"]);
    for (const why of ["closed", "photo", "dependency", "session", "other"] as const) expect(actionsFor("ready_line", why)).toEqual(["discard"]);
    for (const why of ["shortfall", "stock", "shift"] as const) expect(actionsFor("start_batch", why)).toEqual(["resend", "discard"]);
  });

  test("send again: a new write with a new Idempotency-Key and new client_uuids; what it held back waits for it again", () => {
    const body = { client_uuid: "pay-1", payments: [{ client_uuid: "row-1", amount: "10.00" }, { client_uuid: "row-2", amount: "5.00" }] };
    const pay = refused({ kind: "pay_batch", subjectId: 41, subjectUuid: "order-1", group: "order:order-1", body, attempts: 3, nextAttemptAt: NOW }, { message_key: "payments.shift.required" }, 422);
    const after = refused({ kind: "cancel_order", dependsOn: pay.id, subjectId: 41 }, { message_key: "payments.shift.required" }, 422, pay.id);
    const other = refused({ kind: "ready_line" }, { message_key: "inventory.shift.required" });
    let made = 0;
    const fresh = () => `new-${++made}`;

    const queue = resendEntry([pay, after, other], pay.id, fresh);
    const again = queue.find((i) => i.kind === "pay_batch")!;
    expect(again.id).not.toBe(pay.id);
    expect(again.idempotencyKey).not.toBe(pay.idempotencyKey);
    expect(again).toMatchObject({ state: "pending", conflict: null, attempts: 0, nextAttemptAt: 0, seq: pay.seq, subjectId: 41, subjectUuid: "order-1" });
    const sent = again.body as typeof body;
    expect(sent.client_uuid).not.toBe("pay-1");
    expect(new Set([sent.client_uuid, ...sent.payments.map((p) => p.client_uuid)]).size).toBe(3);
    for (const old of ["pay-1", "row-1", "row-2"]) expect(JSON.stringify(sent)).not.toContain(old);
    expect(sent.payments.map((p) => p.amount)).toEqual(["10.00", "5.00"]);
    // the write it held back was never sent: it waits for the new one, with its own key
    const held = queue.find((i) => i.kind === "cancel_order")!;
    expect(held).toMatchObject({ id: after.id, state: "pending", conflict: null, dependsOn: again.id, idempotencyKey: after.idempotencyKey });
    expect(queue.find((i) => i.id === other.id)).toEqual(other);
    expect(nextSendable(queue, 7, NOW)?.id).toBe(again.id);
    // only a refused write can be sent again
    expect(resendEntry(queue, held.id, fresh)).toEqual(queue);
  });

  test("discard: the refused write and the writes it held back leave; the photos they named are let go", () => {
    const upload = refused({ kind: "upload_photos", body: { photos: ["p1", "p2"] } }, { message_key: "outbox.photo_missing" }, 0);
    const batch = refused({ kind: "pay_batch", dependsOn: upload.id, body: { payments: [] } }, { message_key: "outbox.photo_missing" }, 0, upload.id);
    const keep = item({ kind: "serve_line" });
    const { queue, photos } = discardEntry([upload, batch, keep], upload.id);
    expect(queue.map((i) => i.id)).toEqual([keep.id]);
    expect(photos).toEqual(["p1", "p2"]);
    // a pending write is not discarded from the tray
    expect(discardEntry([keep], keep.id)).toEqual({ queue: [keep], photos: [] });
  });

  test("a new send of the order from its cart replaces the order's refused writes; another order's stay", () => {
    const send = refused({ kind: "send_order", subjectUuid: "order-1", inline: true }, shortfallBody);
    const replace = refused({ kind: "replace_lines", subjectUuid: "order-1", subjectId: 41 }, shortfallBody);
    const held = refused({ kind: "send_existing", subjectUuid: "order-1", subjectId: 41, dependsOn: replace.id }, shortfallBody, 409, replace.id);
    const restored = refused({ kind: "send_order", subjectUuid: "order-7", inline: true }, { message_key: "orders.item.not_sellable" }, 422);
    const elsewhere = refused({ kind: "send_order", subjectUuid: "order-2" }, shortfallBody);
    const kitchen = refused({ kind: "ready_line", subjectUuid: "order-1" }, { message_key: "inventory.shift.required" });
    const waiting = item({ kind: "send_existing", subjectUuid: "order-1" });

    const queue = supersede([send, replace, held, restored, elsewhere, kitchen, waiting], "order-1", [restored.id]);
    expect(queue.map((i) => i.id)).toEqual([elsewhere.id, kitchen.id, waiting.id]);
  });

  test("an order write whose answer was lost is looked up: what the server shows says whether it already happened", () => {
    const sendExisting = item({ kind: "send_existing", subjectId: 41, attempts: 2 });
    const cancel = item({ kind: "cancel_order", subjectId: 41, attempts: 1 });
    // only a write that was tried before without an answer, about an order, refused because the order moved on
    expect(needsLookup(sendExisting, "closed")).toBe(true);
    expect(needsLookup(cancel, "closed")).toBe(true);
    expect(needsLookup({ ...sendExisting, attempts: 0 }, "closed")).toBe(false);
    expect(needsLookup(sendExisting, "shift")).toBe(false);
    expect(needsLookup({ ...sendExisting, subjectId: null }, "closed")).toBe(false);
    expect(needsLookup(item({ kind: "ready_line", subjectId: 90, attempts: 2 }), "closed")).toBe(false);

    for (const status of ["sent", "in_progress", "ready", "served", "closed"]) expect(landed(sendExisting, { status })).toBe(true);
    for (const status of ["open", "cancelled", "voided"]) expect(landed(sendExisting, { status })).toBe(false);
    expect(landed(cancel, { status: "cancelled" })).toBe(true);
    expect(landed(cancel, { status: "sent" })).toBe(false);
    expect(landed(cancel, null)).toBe(false);
  });

  test("edit and resend on the pad: the cart comes back from the refused send", () => {
    let made = 0;
    const fresh = () => `fresh-${++made}`;
    // a shortfall: the server holds the order open, so the cart is that order (same client_uuids) and goes as PUT lines + send
    const short = refused({ kind: "send_order", subjectUuid: "order-1", inline: true }, shortfallBody);
    expect(cartSourceOf(short, fresh)).toEqual({
      orderUuid: "order-1",
      serverOrderId: 41,
      type: "dine_in",
      tableId: 3,
      notes: null,
      lines: [{ client_uuid: "line-1", item_id: 5, qty: "2", modifier_ids: [8], notes: "warm" }],
    });
    // nothing was kept on the server: a new order, new client_uuids for it and its lines
    const body = { client_uuid: "order-9", type: "takeaway", dining_table_id: null, notes: "no sugar", send: true, lines: [{ client_uuid: "line-9", item_id: 6, qty: "1" }] };
    const notSellable = refused({ kind: "send_order", subjectUuid: "order-9", inline: true, body }, { message_key: "orders.item.not_sellable" }, 422);
    expect(cartSourceOf(notSellable, fresh)).toEqual({
      orderUuid: "fresh-1",
      serverOrderId: null,
      type: "takeaway",
      tableId: null,
      notes: "no sugar",
      lines: [{ client_uuid: "fresh-2", item_id: 6, qty: "1" }],
    });
    // a refused change of an order already on the server, without the order in the answer: the screen reads it first
    expect(cartSourceOf(refused({ kind: "send_existing", subjectId: 41, subjectUuid: "order-1" }, { message_key: "identity.acting_session.expired" }, 401), fresh)).toBeNull();

    // the cart: each line with its menu row; a product the menu no longer has is left out; the shortfall on its line
    const menu = [
      { item_id: 5, name_en: "Cake", name_am: "ኬክ", category_id: 1, unit: "pcs", price: "50.00", currency: "ETB", available: "1.0000", low: true, is_new: false, station_id: 1, location_id: 1, modifiers: [] },
    ];
    const source = cartSourceOf(short, fresh)!;
    const cart = cartFrom({ ...source, lines: [...source.lines, { client_uuid: "line-2", item_id: 77, qty: "1" }] }, menu, { shortfall: shortfallBody.shortfall as never, error: null }, [short.id]);
    expect(cart).toMatchObject({ orderUuid: "order-1", serverOrderId: 41, type: "dine_in", tableId: 3, notes: "", sending: false, resolves: [short.id] });
    expect(cart.lines).toEqual([{ uuid: "line-1", item: menu[0], qty: "2", modifierIds: [8], notes: "warm" }]);
    expect(cart.shortfall.map((s) => s.line_client_uuid)).toEqual(["line-1"]);
  });

  test("reconnect: the online event always reconciles; a page that becomes visible again does after a gap", () => {
    expect(RECONNECT_GAP_MS).toBe(30_000);
    expect(afterGap(null, NOW)).toBe(false);
    expect(afterGap(NOW - 29_999, NOW)).toBe(false);
    expect(afterGap(NOW - 30_000, NOW)).toBe(true);
    expect(afterGap(NOW - 5_000, NOW, 1_000)).toBe(true);
  });

  test("the tray speaks both languages: every key it uses is in English and in Amharic", () => {
    const keys = ["tray_region", "tray_what", "tray_held", "tray_device", "edit", "resend", "discard", ...["shortfall", "stock", "shift", "closed", "photo", "dependency", "session", "other"].map((r) => `hint_${r}`)];
    const ethiopic = /[ሀ-፿]/;
    for (const key of keys) {
      const english = (en.outbox as Record<string, string>)[key];
      const amharic = (am.outbox as Record<string, string>)[key];
      expect(english, key).toBeTruthy();
      expect(amharic, key).toMatch(ethiopic);
      expect(amharic).not.toBe(english);
    }
  });
});
