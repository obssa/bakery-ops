import { LEGACY_STORAGE_KEY, migrateLegacy, type OutboxItem, type PhotoRecord } from "./logic";

// Where the outbox lives between page loads (step 5.2a): IndexedDB, database "bakery-ops", object store "outbox", one record per queued
// write (key: its id; the queue's order is its `seq`). A small wrapper of our own instead of a library. Every access can fail (a private
// window, blocked storage, a full disk): the store then keeps the queue in memory for this tab, as the localStorage queue
// of Phase 1 did. Version 2 (step 5.2b) adds the object store "photos": the receipt photos kept on the tablet until they are up, one
// record per photo with its blob (key: its client_uuid). The upgrade only adds a store; the writes a tablet already queued stay.
// ADR 0029: every tab of the tablet shares the one store. A tab writes only what it changed (`apply`: the records it put or dropped, never
// a clear), so two tabs never erase each other's writes; mergeQueue folds what the other tabs wrote into a tab's own view, and diffQueue is
// what a tab still has to write. A newer page upgrading the database closes this tab's connection: the next use opens it again.

export const OUTBOX_DB = "bakery-ops";
export const OUTBOX_STORE = "outbox";
export const PHOTO_STORE = "photos";
export const DB_VERSION = 2;

export interface OutboxDb {
  /** every record, in the queue's order */
  all(): Promise<OutboxItem[]>;
  /**
   * Writes these records and drops these ids, in one transaction, and touches nothing else: the other tabs' records stay (ADR 0029; the
   * outbox holds tens of writes, not thousands).
   */
  apply(puts: readonly OutboxItem[], deletes: readonly string[]): Promise<void>;
  /** counts the times the connection was opened again (a newer page upgraded the database): a tab then writes its whole queue again */
  epoch(): number;
}

export interface PhotoDb {
  /** every photo kept on the tablet */
  photos(): Promise<PhotoRecord[]>;
  photo(clientUuid: string): Promise<PhotoRecord | null>;
  putPhoto(photo: PhotoRecord): Promise<void>;
  /** drops these photos (one not kept is no error) */
  dropPhotos(clientUuids: readonly string[]): Promise<void>;
}

/** What an upgrade needs of the database being opened (IDBDatabase, or a fake in the logic spec). */
export interface UpgradeTarget {
  objectStoreNames: { contains(name: string): boolean };
  createObjectStore(name: string, options: { keyPath: string }): unknown;
}

/** The stores, by the version that brought them: 1 the outbox (step 5.2a), 2 the photos (step 5.2b). */
const STORES: readonly { name: string; keyPath: string }[] = [
  { name: OUTBOX_STORE, keyPath: "id" },
  { name: PHOTO_STORE, keyPath: "clientUuid" },
];

/**
 * Creates the stores the database does not have yet, and names them. It only adds: an upgrade never deletes or rewrites a store, so the
 * writes a tablet queued before it are still there after it. It looks at the stores, not at the old version number: a database first opened
 * without a version (a page that reads it before the app does) is version 1 with no store at all.
 */
export function upgradeDb(db: UpgradeTarget): string[] {
  const created: string[] = [];
  for (const store of STORES) {
    if (db.objectStoreNames.contains(store.name)) continue;
    db.createObjectStore(store.name, { keyPath: store.keyPath });
    created.push(store.name);
  }
  return created;
}

/** The queue's order: by `seq`; two tabs that queued at the same moment may share one, then the older write, then the id. */
export const bySeq = (a: OutboxItem, b: OutboxItem) => a.seq - b.seq || a.createdAt - b.createdAt || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);

/** An outbox database in memory (tests, and a browser without IndexedDB). Records are copied in and out, as IndexedDB's are. */
export function memoryOutboxDb(seed: OutboxItem[] = []): OutboxDb {
  const records = new Map<string, OutboxItem>(seed.map((i) => [i.id, structuredClone(i)]));
  return {
    all: async () => [...records.values()].map((i) => structuredClone(i)).sort(bySeq),
    apply: async (puts, deletes) => {
      for (const id of deletes) records.delete(id);
      for (const item of puts) records.set(item.id, structuredClone(item));
    },
    epoch: () => 0,
  };
}

/** What a tab last wrote to (or read from) the store, by id: the base mergeQueue and diffQueue compare its queue with. */
export type Saved = ReadonlyMap<string, OutboxItem>;

export function savedOf(items: readonly OutboxItem[]): Map<string, OutboxItem> {
  return new Map(items.map((i) => [i.id, i]));
}

/**
 * What a tab has to write so the store holds its queue: the records it changed since `saved` (a changed record is a new object: the
 * queue's rules never change one in place), and the ids it dropped. `all`: every record again (the connection was opened again).
 */
export function diffQueue(saved: Saved, queue: readonly OutboxItem[], all = false): { puts: OutboxItem[]; deletes: string[] } {
  const ids = new Set(queue.map((i) => i.id));
  return {
    puts: queue.filter((i) => all || saved.get(i.id) !== i),
    deletes: [...saved.keys()].filter((id) => !ids.has(id)),
  };
}

/**
 * A tab's queue after another tab wrote to the store (ADR 0029): a three-way merge on `saved`, what this tab last wrote or read.
 * - A record this tab changed, or queued and has not written yet, stays as this tab has it.
 * - A record this tab dropped (sent, discarded) stays dropped.
 * - A record this tab had written that the store no longer holds was dropped by another tab (sent there): it leaves.
 * - Any other record is the store's (a write another tab queued, sent or refused).
 */
export function mergeQueue(saved: Saved, local: readonly OutboxItem[], stored: readonly OutboxItem[]): OutboxItem[] {
  const merged = new Map<string, OutboxItem>();
  const mine = new Map(local.map((i) => [i.id, i]));
  for (const record of stored) {
    const own = mine.get(record.id);
    if (own) merged.set(record.id, saved.get(record.id) === own ? record : own);
    else if (!saved.has(record.id)) merged.set(record.id, record);
  }
  for (const own of local) {
    if (merged.has(own.id)) continue;
    // not in the store: new here and not written yet (kept), or written once and dropped since by another tab (it was sent there: it
    // leaves, even when this tab had changed it since, such as a retry's wait)
    if (!saved.has(own.id)) merged.set(own.id, own);
  }
  return [...merged.values()].sort(bySeq);
}

/** An in-memory photo store (tests, and a browser without IndexedDB: the photos then live as long as the tab). */
export function memoryPhotoDb(): PhotoDb {
  const records = new Map<string, PhotoRecord>();
  return {
    photos: async () => [...records.values()],
    photo: async (clientUuid) => records.get(clientUuid) ?? null,
    putPhoto: async (photo) => {
      records.set(photo.clientUuid, photo);
    },
    dropPhotos: async (clientUuids) => {
      for (const clientUuid of clientUuids) records.delete(clientUuid);
    },
  };
}

function done(request: IDBRequest | IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    if ("oncomplete" in request) {
      request.oncomplete = () => resolve();
      request.onabort = () => reject(request.error ?? new Error("aborted"));
      request.onerror = () => reject(request.error);
    } else {
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    }
  });
}

function openRequest(version?: number): Promise<IDBDatabase> {
  return new Promise<IDBDatabase>((resolve, reject) => {
    const open = version === undefined ? indexedDB.open(OUTBOX_DB) : indexedDB.open(OUTBOX_DB, version);
    open.onupgradeneeded = () => {
      upgradeDb(open.result);
    };
    open.onsuccess = () => resolve(open.result);
    open.onerror = () => reject(open.error);
    open.onblocked = () => reject(new Error("blocked"));
  });
}

/**
 * Opens the database (creates it on the first start, upgrades it after a deploy); null when this browser has no IndexedDB or refuses it.
 * A newer page (another tab after a deploy) upgrading it closes this connection; the next use opens it again at whatever version it then
 * has (an upgrade only adds stores, so this page's are still there), so this tab's writes are not lost in silence (ADR 0029). A use that
 * still fails rejects, and the store then says the queue is kept in this tab only.
 */
export async function openOutboxDb(): Promise<(OutboxDb & PhotoDb) | null> {
  if (typeof indexedDB === "undefined") return null;
  let db: IDBDatabase | null;
  try {
    db = await openRequest(DB_VERSION);
  } catch {
    return null;
  }
  let opened = 0;
  let reopening: Promise<IDBDatabase> | null = null;
  const watch = (target: IDBDatabase) => {
    // a newer page upgrades the database: this one lets go, and opens it again on its next use
    target.onversionchange = () => {
      target.close();
      if (db === target) db = null;
    };
    // the browser closed it on its own (site data cleared, a disk error)
    target.onclose = () => {
      if (db === target) db = null;
    };
  };
  watch(db);
  const conn = async (): Promise<IDBDatabase> => {
    if (db) return db;
    reopening ??= openRequest().then(
      (again) => {
        watch(again);
        db = again;
        opened += 1;
        reopening = null;
        return again;
      },
      (error: unknown) => {
        reopening = null;
        throw error;
      },
    );
    return reopening;
  };
  /** a transaction on the connection; one refused because the connection had just closed is tried once more on a new one */
  const tx = async (stores: string, mode: IDBTransactionMode): Promise<IDBTransaction> => {
    const first = await conn();
    try {
      return first.transaction(stores, mode);
    } catch (error) {
      if (!(error instanceof DOMException) || error.name !== "InvalidStateError") throw error;
      if (db === first) db = null;
      return (await conn()).transaction(stores, mode);
    }
  };
  return {
    epoch: () => opened,
    all: async () => {
      const read = (await tx(OUTBOX_STORE, "readonly")).objectStore(OUTBOX_STORE).getAll();
      await done(read);
      return (read.result as OutboxItem[]).sort(bySeq);
    },
    apply: async (puts, deletes) => {
      if (puts.length === 0 && deletes.length === 0) return;
      const t = await tx(OUTBOX_STORE, "readwrite");
      const store = t.objectStore(OUTBOX_STORE);
      for (const id of deletes) store.delete(id);
      for (const item of puts) store.put(item);
      await done(t);
    },
    photos: async () => {
      const read = (await tx(PHOTO_STORE, "readonly")).objectStore(PHOTO_STORE).getAll();
      await done(read);
      return read.result as PhotoRecord[];
    },
    photo: async (clientUuid) => {
      const read = (await tx(PHOTO_STORE, "readonly")).objectStore(PHOTO_STORE).get(clientUuid);
      await done(read);
      return (read.result as PhotoRecord | undefined) ?? null;
    },
    putPhoto: async (photo) => {
      const t = await tx(PHOTO_STORE, "readwrite");
      t.objectStore(PHOTO_STORE).put(photo);
      await done(t);
    },
    dropPhotos: async (clientUuids) => {
      if (clientUuids.length === 0) return;
      const t = await tx(PHOTO_STORE, "readwrite");
      const store = t.objectStore(PHOTO_STORE);
      for (const clientUuid of clientUuids) store.delete(clientUuid);
      await done(t);
    },
  };
}

/**
 * The queue at start: what IndexedDB holds, plus the old localStorage queue (steps 1.6 to 5.1) moved in on the first start. The old key
 * is cleared only once the move is written; a move that failed keeps it for the next start, and the items still go out from this tab.
 * `durable` is false when the queue lives in memory only.
 */
export async function loadOutbox(
  db: OutboxDb | null,
  storage: Pick<Storage, "getItem" | "removeItem"> | null,
): Promise<{ items: OutboxItem[]; durable: boolean }> {
  let stored: OutboxItem[] = [];
  let durable = db !== null;
  if (db) {
    try {
      stored = await db.all();
    } catch {
      durable = false;
    }
  }

  let raw: string | null = null;
  try {
    raw = storage?.getItem(LEGACY_STORAGE_KEY) ?? null;
  } catch {
    raw = null;
  }
  if (raw === null) return { items: stored, durable };

  const known = new Set(stored.map((i) => i.id));
  const last = stored.reduce((max, i) => Math.max(max, i.seq), 0);
  const moved = migrateLegacy(raw)
    .filter((i) => !known.has(i.id))
    .map((i, index) => ({ ...i, seq: last + index + 1 }));
  const items = [...stored, ...moved];
  if (db && durable) {
    try {
      if (moved.length > 0) await db.apply(moved, []);
      storage?.removeItem(LEGACY_STORAGE_KEY);
    } catch {
      durable = false;
    }
  }
  return { items, durable };
}
