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
