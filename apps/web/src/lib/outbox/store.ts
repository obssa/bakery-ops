"use client";

import { create } from "zustand";
import { ApiError, isActingExpired, request } from "../api/client";
import { uuid } from "../api/idempotency";
import { receiptForm, UPLOAD_TIMEOUT_MS } from "../api/till";
import type { NewOrder, NewOrderLine, RejectBody, StoredFileView } from "../api/types";
import { actingHooks, useSession } from "../session";
import { actingHeaders } from "../acting";
import { loadOutbox, memoryPhotoDb, openOutboxDb, type OutboxDb, type PhotoDb } from "./db";
import {
  abandonedPhotos,
  groupOf,
  LocalRefusal,
  nextSendable,
  nextWakeAt,
  outcomeOf,
  photoQueueLimit,
  photoQuota,
  photosOf,
  refuse,
  resumeAll,
  retryLater,
  screenOf,
  sendPhotos,
  settle,
  type OutboxItem,
  type OutboxKind,
  type PhotoRecord,
} from "./logic";

// The outbox (step 1.6a; IndexedDB and every station write without a PIN since step 5.2a, plan 10.2 D10): the writes a screen must not
// lose to a bad connection. Each is queued with its Idempotency-Key (and the client UUID of what it creates) fixed at enqueue, kept in
// IndexedDB (db.ts; in memory when the browser refuses it), and sent by the rules of logic.ts: strict order per order, after the write it
// depends on, again with the SAME key after a network failure or a 5xx (1, 2, 4 ... 60 s, at once when the browser comes back online),
// and moved to the conflict list on any other refusal (the tray is step 5.2c; until then the shell strip shows them). A device refusal
// keeps everything (step 5.1b). Screens that wait for the answer (the till, the production board, the count drafts) `submit()`: online
// they get the answer or the refusal as before; offline, or when the write cannot go now, they are told it is queued.
// Step 5.2b: the till's receipt photos are kept on the tablet (keepPhoto, within the quota) until the `upload_photos` write that sends them
// was accepted; the payment batch waits for that write and takes each file id from its answer. A photo nothing needs is dropped
// (releasePhotos).

export type { OutboxItem, OutboxKind } from "./logic";

export interface OutboxFailure {
  id: string;
  kind: OutboxKind;
  subjectId: number | null;
  subjectUuid: string | null;
  /** shown by the screen that queued it, not by the shell strip */
  inline: boolean;
  /** the API own error, kept whole: a refused send carries its shortfall in `body` */
  error: ApiError;
  at: number;
}

export interface EnqueueInput {
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body: unknown;
  subjectId: number | null;
  subjectUuid?: string | null;
  headers?: Record<string, string>;
  /** the write that must be accepted first */
  dependsOn?: string | null;
  needs?: OutboxItem["needs"];
  /** strict order with the other writes of this group (default: the order or the line, logic.ts groupOf) */
  group?: string | null;
  inline?: boolean;
  idempotencyKey?: string;
}

interface OutboxState {
  /** the writes waiting to be sent, oldest first */
  items: OutboxItem[];
  /** the writes the API refused, as the screens show them */
  failures: OutboxFailure[];
  /** the whole queue, conflicts included, as IndexedDB holds it */
  queue: OutboxItem[];
  /** true once the saved queue was read (until then a screen cannot tell what is queued) */
  loaded: boolean;
  /** false when the queue lives in this tab's memory only (no IndexedDB) */
  durable: boolean;
  /** true while a send is on the wire */
  sending: boolean;
  /** the write on the wire */
  sendingId: string | null;
  enqueue: (input: EnqueueInput) => OutboxItem | null;
  dismissFailure: (id: string) => void;
  flush: () => Promise<void>;
}

const failureCache = new Map<string, OutboxFailure>();

function failureOf(item: OutboxItem): OutboxFailure {
  const cached = failureCache.get(item.id);
  if (cached && cached.at === item.conflict?.at) return cached;
  const conflict = item.conflict ?? { status: 0, body: {}, at: 0, blockedBy: null };
  const failure: OutboxFailure = {
    id: item.id,
    kind: item.kind,
    subjectId: item.subjectId,
    subjectUuid: item.subjectUuid,
    inline: item.inline,
    error: new ApiError(conflict.status, conflict.body),
    at: conflict.at,
  };
  failureCache.set(item.id, failure);
  return failure;
}

function view(queue: OutboxItem[]): Pick<OutboxState, "queue" | "items" | "failures"> {
  const conflicts = queue.filter((i) => i.state === "conflict");
  for (const id of failureCache.keys()) if (!conflicts.some((i) => i.id === id)) failureCache.delete(id);
  return { queue, items: queue.filter((i) => i.state === "pending"), failures: conflicts.map(failureOf) };
}

let db: OutboxDb | null = null;
let writing: Promise<void> = Promise.resolve();

/** Writes the queue as it now is (one write after another, so the last one wins). */
function persist(queue: OutboxItem[]): void {
  const target = db;
  if (!target) return;
  writing = writing.then(() => target.replaceAll(queue)).catch(() => {});
}

export type OutboxOutcome = "accepted" | "refused";
type Listener = (item: OutboxItem, outcome: OutboxOutcome) => void;
const appliedListeners = new Set<Listener>();

/** Called after a queued write was accepted (or refused) by the API (a screen then looks at its feed at once). */
export function onOutboxApplied(listener: Listener): () => void {
  appliedListeners.add(listener);
  return () => {
    appliedListeners.delete(listener);
  };
}

/** What a screen that waits for its write is told: the answer, or that the write is queued (offline, or it could not go now). */
export type Submitted<T> = { sent: true; answer: T } | { sent: false; item: OutboxItem };
interface Waiter {
  resolve: (outcome: Submitted<unknown>) => void;
  reject: (error: unknown) => void;
}
const waiters = new Map<string, Waiter>();

/**
 * The screens still waiting are told their write is queued (the pass ended without it), or, when it was refused with the write it depended
 * on (a payment after its photos' upload), given that refusal: the screen shows it, and the write is not kept as a conflict.
 */
function releaseWaiters(): void {
  const { queue } = useOutbox.getState();
  const told = new Set<string>();
  for (const [id, waiter] of waiters) {
    const item = queue.find((i) => i.id === id);
    waiters.delete(id);
    if (!item) continue;
    if (item.state === "conflict" && item.conflict) {
      told.add(id);
      waiter.reject(new ApiError(item.conflict.status, item.conflict.body));
    } else {
      waiter.resolve({ sent: false, item });
    }
  }
  if (told.size > 0) {
    // the write that refused them goes too, when nothing else waits on it: the screen showed its refusal (a photo's upload)
    const blockers = new Set(queue.flatMap((i) => (told.has(i.id) && i.conflict?.blockedBy ? [i.conflict.blockedBy] : [])));
    let rest = queue.filter((i) => !told.has(i.id));
    const shown = rest.filter((i) => blockers.has(i.id) && !rest.some((o) => o.dependsOn === i.id || o.conflict?.blockedBy === i.id));
    rest = rest.filter((i) => !shown.includes(i));
    persist(rest);
    useOutbox.setState(view(rest));
    const photos = shown.flatMap(photosOf);
    if (photos.length > 0) void releasePhotos(photos);
  }
}

// The receipt photos kept on the tablet (step 5.2b): in IndexedDB once the database is open; in this tab's memory before that, without
// IndexedDB, or when a write to it failed (a full disk), as the queue itself is then.
let photoDb: PhotoDb | null = null;
const photosInMemory = memoryPhotoDb();
const photoStore: PhotoDb = {
  photos: async () => {
    const stored = photoDb ? await photoDb.photos().catch(() => [] as PhotoRecord[]) : [];
    return [...stored, ...(await photosInMemory.photos())];
  },
  photo: async (clientUuid) => (await photosInMemory.photo(clientUuid)) ?? (photoDb ? await photoDb.photo(clientUuid) : null),
  putPhoto: async (photo) => {
    if (photoDb) {
      try {
        await photoDb.putPhoto(photo);
        await photosInMemory.dropPhotos([photo.clientUuid]);
        return;
      } catch {
        // kept in memory below
      }
    }
    await photosInMemory.putPhoto(photo);
  },
  dropPhotos: async (clientUuids) => {
    await photosInMemory.dropPhotos(clientUuids);
    if (photoDb) await photoDb.dropPhotos(clientUuids).catch(() => {});
  },
};

const photoHolders = new Set<() => Iterable<string>>();

/** A screen that holds photos not queued yet (the till's open pay sheets) names them, so they are not dropped under it. */
export function holdPhotos(holder: () => Iterable<string>): () => void {
  photoHolders.add(holder);
  return () => {
    photoHolders.delete(holder);
  };
}

/**
 * Drops the photos nothing needs any more (logic.ts abandonedPhotos): the ones named here at once (a dismissed write's, a replaced or removed
 * row's, an accepted upload's) unless a queued write or an open pay sheet still needs them, and any other after 12 hours.
 */
export async function releasePhotos(clientUuids: readonly string[] = []): Promise<void> {
  try {
    const held = new Set<string>();
    for (const holder of photoHolders) for (const clientUuid of holder()) held.add(clientUuid);
    const drop = abandonedPhotos(await photoStore.photos(), useOutbox.getState().queue, held, new Set(clientUuids), Date.now());
    if (drop.length > 0) await photoStore.dropPhotos(drop);
  } catch {
    // dropped by a later call
  }
}

const PHOTO_QUEUE_LIMIT = photoQueueLimit(process.env.NEXT_PUBLIC_PHOTO_QUEUE_MB);

export type KeptPhoto = { kept: true; photo: PhotoRecord } | { kept: false; waitingBytes: number; limitBytes: number };

/**
 * Keeps a shrunk receipt photo on the tablet, with its own client_uuid and Idempotency-Key fixed now, unless the photos still waiting for
 * their upload would pass NEXT_PUBLIC_PHOTO_QUEUE_MB (50 MB by default): then nothing is kept, and the till says how much waits.
 */
export async function keepPhoto(blob: Blob): Promise<KeptPhoto> {
  const quota = photoQuota(await photoStore.photos(), blob.size, PHOTO_QUEUE_LIMIT);
  if (!quota.allowed) return { kept: false, waitingBytes: quota.waitingBytes, limitBytes: quota.limitBytes };
  const photo: PhotoRecord = {
    clientUuid: uuid(),
    key: uuid(),
    purpose: "receipt",
    blob,
    bytes: blob.size,
    userId: useSession.getState().me?.user.id ?? 0,
    createdAt: Date.now(),
    fileId: null,
  };
  await photoStore.putPhoto(photo);
  return { kept: true, photo };
}

/** Sends a queued write: an `upload_photos` write sends its photos (POST /files each, its own client_uuid and key), any other its request. */
function send(item: OutboxItem, token: string): Promise<unknown> {
  if (item.kind !== "upload_photos") {
    return request(item.path, { method: item.method, body: item.body, idempotencyKey: item.idempotencyKey, token, headers: item.headers });
  }
  return sendPhotos(photosOf(item), photoStore, async (photo) => {
    try {
      return await request<StoredFileView>("/files", {
        method: "POST",
        body: receiptForm(photo.blob, photo.clientUuid, photo.purpose),
        idempotencyKey: photo.key,
        token,
        timeoutMs: UPLOAD_TIMEOUT_MS,
      });
    } catch (thrown) {
      // a refusal names the photo it is about, so the screen (and the tray) can say which
      if (thrown instanceof ApiError && outcomeOf(thrown.status, thrown.key) === "conflict") {
        throw new ApiError(thrown.status, { ...thrown.body, photo_client_uuid: photo.clientUuid } as ConstructorParameters<typeof ApiError>[1]);
      }
      throw thrown;
    }
  });
}

let wakeTimer: ReturnType<typeof setTimeout> | null = null;
/** true after a device refusal: no timer sends again (only a new write, the online event or a sign-in tries once more) */
let deviceHalt = false;

function scheduleWake(): void {
  if (wakeTimer !== null) clearTimeout(wakeTimer);
  wakeTimer = null;
  const me = useSession.getState().me;
  if (!me || deviceHalt) return;
  const at = nextWakeAt(useOutbox.getState().queue, me.user.id);
  if (at === null) return;
  wakeTimer = setTimeout(() => {
    wakeTimer = null;
    void useOutbox.getState().flush();
  }, Math.max(0, at - Date.now()));
}

let seqFloor = 0;

export const useOutbox = create<OutboxState>()((set, get) => {
  const commit = (queue: OutboxItem[]) => {
    persist(queue);
    set(view(queue));
  };

  return {
    items: [],
    failures: [],
    queue: [],
    loaded: false,
    durable: false,
    sending: false,
    sendingId: null,

    enqueue: (input) => {
      const me = useSession.getState().me;
      if (!me) return null;
      const queue = get().queue;
      const seq = Math.max(seqFloor, ...queue.map((i) => i.seq)) + 1;
      seqFloor = seq;
      const subjectUuid = input.subjectUuid ?? null;
      const item: OutboxItem = {
        id: uuid(),
        seq,
        kind: input.kind,
        method: input.method,
        path: input.path,
        body: input.body,
        idempotencyKey: input.idempotencyKey ?? uuid(),
        ...(input.headers ? { headers: input.headers } : {}),
        subjectId: input.subjectId,
        subjectUuid,
        group: input.group !== undefined ? input.group : groupOf(input.kind, subjectUuid, input.subjectId),
        dependsOn: input.dependsOn ?? null,
        needs: input.needs ?? null,
        inline: input.inline ?? false,
        userId: me.user.id,
        screen: typeof window === "undefined" ? null : screenOf(window.location.pathname),
        createdAt: Date.now(),
        attempts: 0,
        nextAttemptAt: 0,
        state: "pending",
        conflict: null,
      };
      commit([...queue, item]);
      void get().flush();
      return item;
    },

    dismissFailure: (id) => {
      const queue = get().queue;
      const dismissed = queue.find((i) => i.id === id && i.state === "conflict");
      if (!dismissed) return;
      commit(queue.filter((i) => i.id !== id));
      // its photos go with it, unless another write or an open pay sheet still needs them
      const photos = photosOf(dismissed);
      if (photos.length > 0) void releasePhotos(photos);
    },

    flush: async () => {
      if (get().sending) return;
      const { token, me } = useSession.getState();
      if (!token || !me || !get().loaded) {
        releaseWaiters();
        return;
      }
      set({ sending: true });
      deviceHalt = false;
      try {
        for (;;) {
          const item = nextSendable(get().queue, me.user.id, Date.now());
          if (!item) return;
          let answer: unknown;
          try {
            set({ sendingId: item.id });
            answer = await send(item, token);
          } catch (thrown) {
            // a refusal made here (a photo no longer on the tablet) is a conflict like the API's, with status 0
            const local = thrown instanceof LocalRefusal;
            const error = local ? new ApiError(0, thrown.body) : thrown instanceof ApiError ? thrown : new ApiError(0, {});
            const outcome = local ? "conflict" : outcomeOf(error.status, error.key);
            if (outcome === "signed_out") {
              // the account's session ended: everything waits, with its key, for the next sign-in
              useSession.getState().expire();
              return;
            }
            if (outcome === "device") {
              // this tablet is not enrolled (or was revoked): the "not enrolled" screen says so; the write waits, with its key, until an
              // admin enrols the device and the page starts again (step 5.1b)
              deviceHalt = true;
              return;
            }
            if (outcome === "conflict") {
              const waiter = waiters.get(item.id);
              const refused = refuse(get().queue, item.id, { status: error.status, body: error.body }, Date.now());
              if (waiter) {
                // the screen is waiting for this write and shows the refusal itself: it is not kept as a conflict
                waiters.delete(item.id);
                commit(refused.filter((i) => i.id !== item.id));
                waiter.reject(error);
              } else {
                commit(refused);
              }
              if (isActingExpired(error)) actingHooks.onExpired();
              appliedListeners.forEach((l) => l(item, "refused"));
              continue;
            }
            // the network or the server failed: keep it, same key, try again later
            commit(retryLater(get().queue, item.id, Date.now()));
            const waiter = waiters.get(item.id);
            if (waiter) {
              waiters.delete(item.id);
              waiter.resolve({ sent: false, item });
            }
            // no answer at all: the connection is down for every write, the next pass is the timer's (or the online event's)
            if (error.isNetwork) return;
            continue;
          }
          commit(settle(get().queue, item.id, answer));
          // the photos are up: kept no longer, unless an open pay sheet still holds them (its payment was refused and is tried again)
          if (item.kind === "upload_photos") void releasePhotos(photosOf(item));
          const waiter = waiters.get(item.id);
          if (waiter) {
            waiters.delete(item.id);
            waiter.resolve({ sent: true, answer });
          }
          appliedListeners.forEach((l) => l(item, "accepted"));
        }
      } finally {
        set({ sending: false, sendingId: null });
        releaseWaiters();
        scheduleWake();
      }
    },
  };
});

/**
 * Queues a write a screen waits for. Online, the screen gets the API's answer, or its refusal thrown as before (the write is then not kept
 * as a conflict: the screen shows it); offline, or when the write cannot go now (the network failed, earlier writes of its order wait), the
 * screen is told it is queued and the outbox sends it later with the same key.
 */
export function submit<T>(input: EnqueueInput): Promise<Submitted<T>> {
  return new Promise<Submitted<T>>((resolve, reject) => {
    const offline = typeof navigator !== "undefined" && navigator.onLine === false;
    const waiter: Waiter = { resolve: resolve as Waiter["resolve"], reject };
    // enqueue starts a send at once; its answer comes after an await, by which time the waiter below is registered
    const item = useOutbox.getState().enqueue(input);
    if (!item) {
      reject(new ApiError(401, { message_key: "auth.unauthenticated" }));
      return;
    }
    if (offline) {
      resolve({ sent: false, item });
      return;
    }
    // a flush already on the wire picks the item up (or tells the waiter it is queued); otherwise this one does
    waiters.set(item.id, waiter);
    void useOutbox.getState().flush();
  });
}

let started = false;

/** Everything waiting may go now (the connection is back, someone signed in, the queue was just read). */
function resume(): void {
  const queue = resumeAll(useOutbox.getState().queue);
  persist(queue);
  useOutbox.setState(view(queue));
  void useOutbox.getState().flush();
}

/** Reads the saved queue (moving the old localStorage queue in) and resumes sending; once when the app starts. */
export function startOutbox(): () => void {
  const online = () => {
    // a page that started while the API could not be reached knows nobody yet: ask again, the outbox goes on once it does
    const session = useSession.getState();
    if (session.status === "unknown" && session.unreachable) void session.init();
    resume();
  };

  if (!started) {
    started = true;
    void (async () => {
      const opened = await openOutboxDb();
      db = opened;
      let storage: Storage | null = null;
      try {
        storage = window.localStorage;
      } catch {
        storage = null;
      }
      const { items, durable } = await loadOutbox(db, storage);
      if (!durable) db = null;
      photoDb = durable ? opened : null;
      // writes queued while the saved queue was being read go after it
      const known = new Set(items.map((i) => i.id));
      const last = items.reduce((max, i) => Math.max(max, i.seq), 0);
      const early = useOutbox.getState().queue.filter((i) => !known.has(i.id)).map((i, index) => ({ ...i, seq: last + index + 1 }));
      const queue = [...items, ...early];
      seqFloor = Math.max(seqFloor, last + early.length);
      persist(queue);
      useOutbox.setState({ ...view(queue), loaded: true, durable });
      resume();
      // the photos a reload or a closed tab left behind, that nothing queued needs
      void releasePhotos();
    })();
  } else {
    resume();
  }

  window.addEventListener("online", online);
  const stopWatching = useSession.subscribe((state, previous) => {
    if (state.status === "signed-in" && previous.status !== "signed-in") resume();
  });
  return () => {
    window.removeEventListener("online", online);
    stopWatching();
    if (wakeTimer !== null) clearTimeout(wakeTimer);
    wakeTimer = null;
  };
}

// The kitchen and bar displays (step 1.6a), one line each: their order is the line's.

/** POST /orders with send:true. The body carries the order and line client UUIDs the caller fixed. */
export function queueSendOrder(order: NewOrder): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "send_order", method: "POST", path: "/orders", body: { ...order, send: true }, subjectId: null, subjectUuid: order.client_uuid });
}

export function queueAcceptLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "accept_line", method: "POST", path: `/order-lines/${lineId}/accept`, body: {}, subjectId: lineId });
}

export function queueReadyLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "ready_line", method: "POST", path: `/order-lines/${lineId}/ready`, body: {}, subjectId: lineId });
}

export function queueRejectLine(lineId: number, body: RejectBody): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "reject_line", method: "POST", path: `/order-lines/${lineId}/reject`, body, subjectId: lineId });
}

// The pad writes. Each carries the acting headers of the moment the waiter tapped; the sends are answered on the screen
// (inline), so a refusal such as the shortfall lands on its line and not in the shell strip. An order's writes go by its client UUID.

/** POST /orders with send:true for a new cart. */
export function queuePadSend(order: NewOrder): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "send_order",
    method: "POST",
    path: "/orders",
    body: { ...order, send: true },
    subjectId: null,
    subjectUuid: order.client_uuid,
    headers: actingHeaders(),
    inline: true,
  });
}

/** PUT /orders/{id}/lines: the open order lines become the cart lines (after a shortfall the waiter fixed a line). */
export function queueReplaceLines(orderId: number, orderUuid: string, lines: NewOrderLine[]): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "replace_lines",
    method: "PUT",
    path: `/orders/${orderId}/lines`,
    body: { lines },
    subjectId: orderId,
    subjectUuid: orderUuid,
    headers: actingHeaders(),
    inline: true,
  });
}

/** POST /orders/{id}/send for an order that is already open on the server; `after` is the replace it waits for. */
export function queueSendExisting(orderId: number, orderUuid: string, after: string | null): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "send_existing",
    method: "POST",
    path: `/orders/${orderId}/send`,
    body: {},
    subjectId: orderId,
    subjectUuid: orderUuid,
    headers: actingHeaders(),
    dependsOn: after,
    inline: true,
  });
}

export function queueServeLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "serve_line", method: "POST", path: `/order-lines/${lineId}/served`, body: {}, subjectId: lineId, headers: actingHeaders() });
}

/**
 * The upload of a payment batch's photos kept on the tablet (step 5.2b), queued before the batch: the batch then depends on it and takes
 * each file id from its answer (photoNeeds).
 */
export function queuePhotoUpload(clientUuids: string[], orderId: number, orderUuid: string | null): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "upload_photos",
    method: "POST",
    path: "/files",
    body: { photos: clientUuids },
    subjectId: orderId,
    subjectUuid: orderUuid,
  });
}

/** POST /orders/{id}/cancel; with the order's client UUID it goes after that order's other queued writes. */
export function queueCancelOrder(orderId: number, orderUuid: string | null = null): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "cancel_order",
    method: "POST",
    path: `/orders/${orderId}/cancel`,
    body: {},
    subjectId: orderId,
    subjectUuid: orderUuid,
    headers: actingHeaders(),
  });
}

/**
 * Whether a write that matches `which` waits in the outbox: saved on this tablet, not on the wire for the first time (a write a screen is
 * sending right now, online, is that screen's own "busy"; after a failure it waits).
 */
export function useWaiting(which: (item: OutboxItem) => boolean): boolean {
  return useOutbox((s) => s.items.some((item) => (item.id !== s.sendingId || item.attempts > 0) && which(item)));
}
