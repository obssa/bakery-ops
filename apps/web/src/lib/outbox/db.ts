import { LEGACY_STORAGE_KEY, migrateLegacy, type OutboxItem } from "./logic";

// Where the outbox lives between page loads (step 5.2a): IndexedDB, database "bakery-ops", object store "outbox", one record per queued
// write (key: its id; the queue's order is its `seq`). A small wrapper of our own (two calls) instead of a library. Every access can fail
// (a private window, blocked storage, a full disk): the store then keeps the queue in memory for this tab and says nothing, as the
// localStorage queue of Phase 1 did. Step 5.2b adds the photo blobs as a second store (a version 2 upgrade).

export const OUTBOX_DB = "bakery-ops";
export const OUTBOX_STORE = "outbox";
const VERSION = 1;

export interface OutboxDb {
  /** every record, in the queue's order */
  all(): Promise<OutboxItem[]>;
  /** the queue becomes exactly `items` (one transaction: the outbox holds tens of writes, not thousands) */
  replaceAll(items: OutboxItem[]): Promise<void>;
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

/** Opens (and on the first start creates) the outbox database; null when this browser offers no IndexedDB or refuses it. */
export async function openOutboxDb(): Promise<OutboxDb | null> {
  if (typeof indexedDB === "undefined") return null;
  let db: IDBDatabase;
  try {
    db = await new Promise<IDBDatabase>((resolve, reject) => {
      const open = indexedDB.open(OUTBOX_DB, VERSION);
      open.onupgradeneeded = () => {
        if (!open.result.objectStoreNames.contains(OUTBOX_STORE)) open.result.createObjectStore(OUTBOX_STORE, { keyPath: "id" });
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
