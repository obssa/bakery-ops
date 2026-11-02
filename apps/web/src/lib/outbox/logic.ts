import { isDeviceRefusal } from "../device";

// The outbox's rules, as pure functions over the list of queued writes (step 5.2a; plan section 4.2 and 10.2 D10). The store
// (store.ts) keeps the list in memory and in IndexedDB (db.ts) and sends what these functions pick:
// - strict order per order: the writes of one order (its `group`) go one after another, and a write waiting on a failure holds up only
//   its own order;
// - `dependsOn`: a write goes only after the one it depends on was accepted, and then with that one's server id written in where it
//   `needs` it (a line action after its order's send, a payment after its photo);
// - a write that failed on the network or a 5xx waits 1, 2, 4 ... 60 s and goes again with the SAME Idempotency-Key; the browser's
//   online event sends everything at once;
// - a refusal (any other 4xx) moves the write, and every write that depends on it, to the conflict list (the tray is step 5.2c);
// - a device refusal keeps everything and sends nothing more until the page starts again (step 5.1b);
// - step 5.2b, the photo queue: a receipt photo is kept on the tablet (db.ts, store "photos") until its upload's write was accepted; a
//   payment waits for that write and goes with each photo's file id where the photo's token was; a new photo is refused while more than
//   NEXT_PUBLIC_PHOTO_QUEUE_MB waits; a photo nothing needs any more is dropped.

export const LEGACY_STORAGE_KEY = "bakery-ops.outbox.v1";

export type OutboxKind =
  // the kitchen and bar displays (step 1.6a)
  | "accept_line"
  | "ready_line"
  | "reject_line"
  // the waiter pad (steps 1.6a and 1.6b)
  | "send_order"
  | "replace_lines"
  | "send_existing"
  | "serve_line"
  | "cancel_order"
  // step 5.2a: the till's payments, the production batch steps, the shift bar's count and cash drafts
  | "pay_batch"
  | "start_batch"
  | "finish_batch"
  | "count_lines"
  | "count_cash"
  // step 5.2b: the receipt photos of one payment batch, kept on the tablet, uploaded one after another (POST /files each)
  | "upload_photos";

/** The screens of the shell (lib/screens.ts), and "shifts" for the count pages; "other" is any other page. */
export type ScreenKey = "pad" | "till" | "kds_kitchen" | "kds_bar" | "production" | "shifts" | "admin" | "other";

export interface Conflict {
  /** the API's status (0 when the write was refused here, not by the API) */
  status: number;
  /** the API's error body, whole: `{message_key, message_en, message_am, ...}` */
  body: Record<string, unknown>;
  at: number;
  /** set on a write that was never sent because the write it depends on was refused */
  blockedBy: string | null;
}

/** A value a write takes from the answer to the write it depends on. */
export interface Need {
  token: string;
  from: string;
}

export interface OutboxItem {
  id: string;
  /** the order of the queue (IndexedDB returns records by id) */
  seq: number;
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body: unknown;
  /** fixed when the write was queued, never changed: every retry is the same write for the API */
  idempotencyKey: string;
  /** headers fixed when the write was queued: the pad's X-Acting-Session (the waiter who tapped, not whoever acts at retry time) */
  headers?: Record<string, string>;
  /** the line or order the write is about, so a screen can show "sending" on it */
  subjectId: number | null;
  /** the order's client_uuid, so the pad finds the answer to its own send */
  subjectUuid: string | null;
  /** the writes of one group (an order) go strictly one after another; null: on its own */
  group: string | null;
  /** the write that must be accepted first */
  dependsOn: string | null;
  /**
   * where the dependency's answer goes: `token` in the path and the body becomes the value at `from` in that answer; a list fills several
   * tokens from the one answer (a payment batch with more than one photo, step 5.2b)
   */
  needs: Need | Need[] | null;
  /** true: the screen that queued it shows a refusal itself (the pad's shortfall on its line), so the shell strip stays quiet */
  inline: boolean;
  /** the account that queued it: only that account sends it */
  userId: number;
  /** the screen it was queued on, for the pending count per screen (null: not known, an item of the old queue) */
  screen: ScreenKey | null;
  createdAt: number;
  /** the failed attempts so far (network or 5xx) */
  attempts: number;
  /** not before this time (ms); 0: now */
  nextAttemptAt: number;
  state: "pending" | "conflict";
  conflict: Conflict | null;
}

export type Outcome = "retry" | "conflict" | "device" | "signed_out";

const MAX_BACKOFF_MS = 60_000;

/** How long a write waits after its `failures`-th failed attempt: 1, 2, 4 ... seconds, at most 60. */
export function backoffMs(failures: number): number {
  if (failures <= 0) return 0;
  return Math.min(MAX_BACKOFF_MS, 1_000 * 2 ** Math.min(failures - 1, 16));
}

/** What an answer of the API (0: no answer at all) does to the write. */
export function outcomeOf(status: number, key: string): Outcome {
  if (status === 0 || status >= 500 || status === 408 || status === 429) return "retry";
  // the same write still running on the server (a slow upload whose answer timed out here, a second tab): its answer comes on a retry
  if (status === 409 && key === "idempotency.in_flight") return "retry";
  if (isDeviceRefusal({ status, key })) return "device";
  if (status === 401 && key !== "identity.acting_session.expired") return "signed_out";
  return "conflict";
}

function pending(items: OutboxItem[]): Set<string> {
  return new Set(items.filter((i) => i.state === "pending").map((i) => i.id));
}

/** Whether `item` may go now, time aside: the first pending write of its group, its dependency no longer waiting. */
function free(item: OutboxItem, items: OutboxItem[], waiting: Set<string>): boolean {
  if (item.state !== "pending") return false;
  if (item.dependsOn && waiting.has(item.dependsOn)) return false;
  // a write that needs its dependency's answer cannot go before it has it
  if (item.needs && item.dependsOn) return false;
  if (item.group === null) return true;
  const head = items.find((i) => i.state === "pending" && i.group === item.group);
  return head?.id === item.id;
}

/** The write to send next for this account at `now`, or null. */
export function nextSendable(items: OutboxItem[], userId: number, now: number): OutboxItem | null {
  const waiting = pending(items);
  return items.find((i) => i.userId === userId && i.nextAttemptAt <= now && free(i, items, waiting)) ?? null;
}

/** When the next write of this account could go (the earliest wait among those free to go), or null when none is. */
export function nextWakeAt(items: OutboxItem[], userId: number): number | null {
  const waiting = pending(items);
  let at: number | null = null;
  for (const i of items) if (i.userId === userId && free(i, items, waiting) && (at === null || i.nextAttemptAt < at)) at = i.nextAttemptAt;
  return at;
}

/** After a failed attempt (network or 5xx): the write stays, with its key, and waits. */
export function retryLater(items: OutboxItem[], id: string, now: number): OutboxItem[] {
  return items.map((i) => (i.id === id ? { ...i, attempts: i.attempts + 1, nextAttemptAt: now + backoffMs(i.attempts + 1) } : i));
}

/** The connection is back: every pending write may go now. */
export function resumeAll(items: OutboxItem[]): OutboxItem[] {
  return items.map((i) => (i.state === "pending" && i.nextAttemptAt !== 0 ? { ...i, nextAttemptAt: 0 } : i));
}

/** The value at `from` in an answer: `id`, `batch.id`, `lines[client_uuid=…].id`. */
export function pick(answer: unknown, from: string): unknown {
  let value: unknown = answer;
  for (const part of from.split(".")) {
    if (value === null || typeof value !== "object") return undefined;
    const select = /^(\w+)\[(\w+)=([^\]]*)\]$/.exec(part);
    if (select) {
      const list = (value as Record<string, unknown>)[select[1]];
      if (!Array.isArray(list)) return undefined;
      value = list.find((entry) => entry !== null && typeof entry === "object" && String((entry as Record<string, unknown>)[select[2]]) === select[3]);
    } else {
      value = (value as Record<string, unknown>)[part];
    }
  }
  return value;
}

function fill(value: unknown, token: string, answer: unknown): unknown {
  if (typeof value === "string") {
    if (value === token) return answer;
    return value.includes(token) ? value.split(token).join(String(answer)) : value;
  }
  if (Array.isArray(value)) return value.map((v) => fill(v, token, answer));
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([k, v]) => [k, fill(v, token, answer)]));
  }
  return value;
}

/** The API accepted write `id`: it leaves the queue, and every write that depended on it is freed and given what it needs. */
export function settle(items: OutboxItem[], id: string, answer: unknown): OutboxItem[] {
  return items
    .filter((i) => i.id !== id)
    .map((i) => {
      if (i.dependsOn !== id) return i;
      if (!i.needs) return { ...i, dependsOn: null };
      const needs = Array.isArray(i.needs) ? i.needs : [i.needs];
      const values = needs.map((need) => pick(answer, need.from));
      if (values.some((value) => value === undefined || value === null)) {
        // the answer does not carry what this write needs: it cannot go, it is a conflict to show
        return {
          ...i,
          dependsOn: null,
          state: "conflict" as const,
          conflict: {
            status: 0,
            body: {
              message_key: "outbox.dependency_answer_missing",
              message_en: "The change it waited for was saved without what this one needs.",
              message_am: "የጠበቀው ለውጥ ይህ የሚያስፈልገውን ሳይይዝ ተቀምጧል።",
            },
            at: Date.now(),
            blockedBy: id,
          },
        };
      }
      let { path, body } = i;
      needs.forEach((need, index) => {
        path = String(fill(path, need.token, values[index]));
        body = fill(body, need.token, values[index]);
      });
      return { ...i, dependsOn: null, needs: null, path, body };
    });
}

/** The API refused write `id` for good: it and every write that depends on it (however far down) become conflicts. */
export function refuse(items: OutboxItem[], id: string, refusal: { status: number; body: Record<string, unknown> }, now: number): OutboxItem[] {
  const blocked = new Set<string>();
  for (let grown = true; grown; ) {
    grown = false;
    for (const i of items) {
      if (i.state === "pending" && i.dependsOn && (i.dependsOn === id || blocked.has(i.dependsOn)) && !blocked.has(i.id)) {
        blocked.add(i.id);
        grown = true;
      }
    }
  }
  return items.map((i) => {
    if (i.id === id) return { ...i, state: "conflict" as const, conflict: { status: refusal.status, body: refusal.body, at: now, blockedBy: null } };
    if (blocked.has(i.id)) return { ...i, state: "conflict" as const, conflict: { status: refusal.status, body: refusal.body, at: now, blockedBy: id } };
    return i;
  });
}

/** The screen of a page path (with or without the /app base path and the locale). */
export function screenOf(pathname: string): ScreenKey {
  const parts = pathname.split("/").filter(Boolean);
  if (parts[0] === "app") parts.shift();
  if (parts[0] === "en" || parts[0] === "am") parts.shift();
  switch (parts[0]) {
    case "pad":
    case "till":
    case "production":
    case "shifts":
    case "admin":
      return parts[0];
    case "kds":
      return parts[1] === "bar" ? "kds_bar" : parts[1] === "kitchen" ? "kds_kitchen" : "other";
    default:
      return "other";
  }
}

/** The writes waiting to be sent from this screen and from the others, and the conflicts. */
export function pendingCounts(items: OutboxItem[], screen: ScreenKey): { here: number; elsewhere: number; conflicts: number } {
  let here = 0;
  let elsewhere = 0;
  let conflicts = 0;
  for (const i of items) {
    if (i.state === "conflict") conflicts += 1;
    else if (i.screen === screen) here += 1;
    else elsewhere += 1;
  }
  return { here, elsewhere, conflicts };
}

const ORDER_KINDS: readonly OutboxKind[] = ["send_order", "replace_lines", "send_existing", "cancel_order", "pay_batch"];
const PAD_KINDS: readonly OutboxKind[] = ["send_order", "replace_lines", "send_existing", "serve_line", "cancel_order"];

/** The group of a write when its caller names none: an order's writes by the order (its client_uuid, else its id), a line's by the line. */
export function groupOf(kind: OutboxKind, subjectUuid: string | null, subjectId: number | null): string | null {
  if (ORDER_KINDS.includes(kind)) return subjectUuid ? `order:${subjectUuid}` : subjectId !== null ? `order#${subjectId}` : null;
  if (subjectId !== null && (kind === "accept_line" || kind === "ready_line" || kind === "reject_line" || kind === "serve_line")) return `line:${subjectId}`;
  return null;
}

interface LegacyItem {
  id: string;
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body?: unknown;
  idempotencyKey: string;
  headers?: Record<string, string>;
  subjectUuid?: string | null;
  after?: string | null;
  inline?: boolean;
  userId: number;
  subjectId?: number | null;
  createdAt?: number;
  attempts?: number;
}

function isLegacyItem(value: unknown): value is LegacyItem {
  if (value === null || typeof value !== "object") return false;
  const v = value as Record<string, unknown>;
  return typeof v.id === "string" && typeof v.path === "string" && typeof v.kind === "string" && typeof v.idempotencyKey === "string" && typeof v.userId === "number";
}

/** The queue of steps 1.6 to 5.1 (localStorage, `bakery-ops.outbox.v1`) as outbox items, in its order. Anything unreadable is left out. */
export function migrateLegacy(raw: string | null): OutboxItem[] {
  if (!raw) return [];
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!Array.isArray(parsed)) return [];
  return parsed.filter(isLegacyItem).map((old, index) => {
    const subjectUuid = old.subjectUuid ?? null;
    const subjectId = typeof old.subjectId === "number" ? old.subjectId : null;
    const pad = PAD_KINDS.includes(old.kind) && (old.kind !== "send_order" || old.inline === true);
    return {
      id: old.id,
      seq: index + 1,
      kind: old.kind,
      method: old.method === "PUT" ? "PUT" : "POST",
      path: old.path,
      body: old.body ?? {},
      idempotencyKey: old.idempotencyKey,
      ...(old.headers ? { headers: old.headers } : {}),
      subjectId,
      subjectUuid,
      group: groupOf(old.kind, subjectUuid, subjectId),
      dependsOn: old.after ?? null,
      needs: null,
      inline: old.inline === true,
      userId: old.userId,
      screen: pad ? "pad" : null,
      createdAt: typeof old.createdAt === "number" ? old.createdAt : 0,
      attempts: typeof old.attempts === "number" ? old.attempts : 0,
      nextAttemptAt: 0,
      state: "pending",
      conflict: null,
    } satisfies OutboxItem;
  });
}

// Step 5.2b: the photo queue. A receipt photo is shrunk in the browser and kept on the tablet (db.ts, store "photos") with its own
// client_uuid and Idempotency-Key; a payment batch whose photos are not up yet goes after one `upload_photos` write that sends them, and
// takes each file id from its answer (photoNeeds) where the photo's token is (photoToken).

/** A receipt photo kept on the tablet until it is up and nothing needs it any more (IndexedDB "bakery-ops", store "photos"). */
export interface PhotoRecord {
  /** the photo's own client_uuid (POST /files), its key in the store */
  clientUuid: string;
  /** the upload's Idempotency-Key, fixed when the photo was taken: every try is the same write */
  key: string;
  purpose: "receipt";
  /** the shrunk JPEG (or the file as picked, when the browser cannot shrink it) */
  blob: Blob;
  /** the blob's size, for the quota */
  bytes: number;
  /** the account that took it */
  userId: number;
  createdAt: number;
  /** the stored file's id once it is up (a retry of the upload skips it); null while it waits */
  fileId: number | null;
}

/** The calls of the photo store the upload needs (db.ts). */
export interface PhotoReader {
  photo(clientUuid: string): Promise<PhotoRecord | null>;
  putPhoto(photo: PhotoRecord): Promise<void>;
}

/** The MB of photos that may wait on the tablet when NEXT_PUBLIC_PHOTO_QUEUE_MB says nothing usable. */
export const PHOTO_QUEUE_MB = 50;
const MB = 1024 * 1024;

/** The quota in bytes: NEXT_PUBLIC_PHOTO_QUEUE_MB when it is a positive number of MB, else 50 MB. */
export function photoQueueLimit(raw: string | undefined): number {
  const mb = raw === undefined || raw.trim() === "" ? Number.NaN : Number(raw);
  return (Number.isFinite(mb) && mb > 0 ? mb : PHOTO_QUEUE_MB) * MB;
}

/** Whether a new photo of `incoming` bytes may be kept: the photos still waiting for their upload (no file id yet) and it stay within. */
export function photoQuota(
  photos: readonly Pick<PhotoRecord, "bytes" | "fileId">[],
  incoming: number,
  limitBytes: number,
): { allowed: boolean; waitingBytes: number; limitBytes: number } {
  const waitingBytes = photos.reduce((sum, photo) => (photo.fileId === null ? sum + photo.bytes : sum), 0);
  return { allowed: waitingBytes + incoming <= limitBytes, waitingBytes, limitBytes };
}

/** What a payment row carries where its photo's file id will be, until the photo is up. */
export function photoToken(clientUuid: string): string {
  return `{{photo:${clientUuid}}}`;
}

/** What a payment batch takes from the answer of its photos' upload: each photo's file id, by its client_uuid, where its token is. */
export function photoNeeds(clientUuids: readonly string[]): Need[] {
  return clientUuids.map((clientUuid) => ({ token: photoToken(clientUuid), from: `files[client_uuid=${clientUuid}].id` }));
}

/** The photos a write uploads (an `upload_photos` write's), by client_uuid; none for any other write. */
export function photosOf(item: Pick<OutboxItem, "kind" | "body">): string[] {
  if (item.kind !== "upload_photos" || item.body === null || typeof item.body !== "object") return [];
  const photos = (item.body as { photos?: unknown }).photos;
  return Array.isArray(photos) ? photos.filter((photo): photo is string => typeof photo === "string") : [];
}

/** A refusal made on the tablet, not by the API: the write becomes a conflict with this body (status 0), and so does what depends on it. */
export class LocalRefusal extends Error {
  constructor(readonly body: { message_key: string; message_en: string; message_am: string } & Record<string, unknown>) {
    super(body.message_en);
    this.name = "LocalRefusal";
  }
}

/**
 * Sends the photos of an `upload_photos` write through `upload` (POST /files), one after another, each with its own client_uuid and
 * Idempotency-Key. A photo already up is not sent again: its file id is kept with it once the API answered, so a retry after a failure
 * halfway sends only the rest. The answer names each file (`{files: [{client_uuid, id}]}`, what photoNeeds reads). A photo no longer on the
 * tablet (storage cleared) is refused here: its payment becomes a conflict rather than going without it.
 */
export async function sendPhotos(
  clientUuids: readonly string[],
  photos: PhotoReader,
  upload: (photo: PhotoRecord) => Promise<{ id: number }>,
): Promise<{ files: { client_uuid: string; id: number }[] }> {
  const files: { client_uuid: string; id: number }[] = [];
  for (const clientUuid of clientUuids) {
    const photo = await photos.photo(clientUuid);
    if (!photo) {
      throw new LocalRefusal({
        message_key: "outbox.photo_missing",
        message_en: "The receipt photo is no longer on this tablet. Take it again.",
        message_am: "የደረሰኙ ፎቶ በዚህ ታብሌት ላይ የለም። እንደገና ያንሱት።",
        photo_client_uuid: clientUuid,
      });
    }
    let id = photo.fileId;
    if (id === null) {
      id = (await upload(photo)).id;
      try {
        await photos.putPhoto({ ...photo, fileId: id });
      } catch {
        // not written down: a retry sends it again with the same client_uuid and key, and the API answers the same file
      }
    }
    files.push({ client_uuid: clientUuid, id });
  }
  return { files };
}

/** A photo nothing needs is dropped after this long, even if nothing let go of it (a pay sheet a reload or a closed tab left behind). */
export const ABANDONED_AFTER_MS = 12 * 3_600_000;

/**
 * The photos to drop from the tablet: no queued write uploads them (pending, or a conflict that may still be sent again) and no open pay
 * sheet of this tab holds them (`held`), and either they were let go of just now (`released`: a dismissed write's, a replaced or removed
 * row's, an accepted upload's) or they are older than `graceMs` (another tab's open sheet is all that could still want them). A photo that
 * reached the server but no payment took is the server's to purge (files:purge-orphans, 24 h).
 */
export function abandonedPhotos(
  photos: readonly Pick<PhotoRecord, "clientUuid" | "createdAt">[],
  queue: readonly OutboxItem[],
  held: ReadonlySet<string>,
  released: ReadonlySet<string>,
  now: number,
  graceMs = ABANDONED_AFTER_MS,
): string[] {
  const needed = new Set(queue.flatMap(photosOf));
  return photos
    .filter((photo) => !needed.has(photo.clientUuid) && !held.has(photo.clientUuid) && (released.has(photo.clientUuid) || now - photo.createdAt > graceMs))
    .map((photo) => photo.clientUuid);
}
