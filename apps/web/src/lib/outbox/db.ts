import { LEGACY_STORAGE_KEY, migrateLegacy, type OutboxItem, type PhotoRecord } from "./logic";

// Where the outbox lives between page loads (step 5.2a): IndexedDB, database "bakery-ops", object store "outbox", one record per queued
// write (key: its id; the queue's order is its `seq`). A small wrapper of our own instead of a library. Every access can fail (a private
// window, blocked storage, a full disk): the store then keeps the queue in memory for this tab and says nothing, as the localStorage queue
// of Phase 1 did. Version 2 (step 5.2b) adds the object store "photos": the receipt photos kept on the tablet until they are up, one
// record per photo with its blob (key: its client_uuid). The upgrade only adds a store; the writes a tablet already queued stay.

export const OUTBOX_DB = "bakery-ops";
export const OUTBOX_STORE = "outbox";
export const PHOTO_STORE = "photos";
export const DB_VERSION = 2;

export interface OutboxDb {
  /** every record, in the queue's order */
  all(): Promise<OutboxItem[]>;
  /** the queue becomes exactly `items` (one transaction: the outbox holds tens of writes, not thousands) */
  replaceAll(items: OutboxItem[]): Promise<void>;
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

const bySeq = (a: OutboxItem, b: OutboxItem) => a.seq - b.seq;

/** An outbox database in memory (tests, and a browser without IndexedDB). */
export function memoryOutboxDb(seed: OutboxItem[] = []): OutboxDb {
  let records = [...seed];
  return {
    all: async () => [...records].sort(bySeq),
    replaceAll: async (items) => {
      records = [...items];
    },
  };
}

/** A photo store in memory (tests, and a browser without IndexedDB: the photos then live as long as the tab). */
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

/** Opens the database (creates it on the first start, upgrades it after a deploy); null when this browser has no IndexedDB or refuses it. */
export async function openOutboxDb(): Promise<(OutboxDb & PhotoDb) | null> {
  if (typeof indexedDB === "undefined") return null;
  let db: IDBDatabase;
  try {
    db = await new Promise<IDBDatabase>((resolve, reject) => {
      const open = indexedDB.open(OUTBOX_DB, DB_VERSION);
      open.onupgradeneeded = () => {
        upgradeDb(open.result);
      };
      open.onsuccess = () => resolve(open.result);
      open.onerror = () => reject(open.error);
      open.onblocked = () => reject(new Error("blocked"));
    });
  } catch {
    return null;
  }
  // a newer page (another tab after a deploy) upgrades the database: this one lets go
  db.onversionchange = () => db.close();
  return {
    all: async () => {
      const read = db.transaction(OUTBOX_STORE, "readonly").objectStore(OUTBOX_STORE).getAll();
      await done(read);
      return (read.result as OutboxItem[]).sort(bySeq);
    },
    replaceAll: async (items) => {
      const tx = db.transaction(OUTBOX_STORE, "readwrite");
      const store = tx.objectStore(OUTBOX_STORE);
      store.clear();
      for (const item of items) store.put(item);
      await done(tx);
    },
    photos: async () => {
      const read = db.transaction(PHOTO_STORE, "readonly").objectStore(PHOTO_STORE).getAll();
      await done(read);
      return read.result as PhotoRecord[];
    },
    photo: async (clientUuid) => {
      const read = db.transaction(PHOTO_STORE, "readonly").objectStore(PHOTO_STORE).get(clientUuid);
      await done(read);
      return (read.result as PhotoRecord | undefined) ?? null;
    },
    putPhoto: async (photo) => {
      const tx = db.transaction(PHOTO_STORE, "readwrite");
      tx.objectStore(PHOTO_STORE).put(photo);
      await done(tx);
    },
    dropPhotos: async (clientUuids) => {
      if (clientUuids.length === 0) return;
      const tx = db.transaction(PHOTO_STORE, "readwrite");
      const store = tx.objectStore(PHOTO_STORE);
      for (const clientUuid of clientUuids) store.delete(clientUuid);
      await done(tx);
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
      if (moved.length > 0) await db.replaceAll(items);
      storage?.removeItem(LEGACY_STORAGE_KEY);
    } catch {
      durable = false;
    }
  }
  return { items, durable };
}
