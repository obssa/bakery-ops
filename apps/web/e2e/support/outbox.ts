import type { Page } from "@playwright/test";
import type { Api } from "./api";

// The outbox as the browser keeps it (step 5.2a): IndexedDB "bakery-ops", object store "outbox", one record per queued write.

export interface QueuedWrite {
  id: string;
  kind: string;
  path: string;
  idempotencyKey: string;
  subjectUuid: string | null;
  state: "pending" | "conflict";
  body: Record<string, unknown>;
}

/** The writes the page's outbox holds in IndexedDB, oldest first (an empty list when the database does not exist yet). */
export function queuedWrites(page: Page): Promise<QueuedWrite[]> {
  return page.evaluate(
    () =>
      new Promise<QueuedWrite[]>((resolve, reject) => {
        const open = indexedDB.open("bakery-ops");
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
          const db = open.result;
          if (!db.objectStoreNames.contains("outbox")) {
            db.close();
            resolve([]);
            return;
          }
          const read = db.transaction("outbox", "readonly").objectStore("outbox").getAll();
          read.onerror = () => reject(read.error);
          read.onsuccess = () => {
            db.close();
            resolve((read.result as (QueuedWrite & { seq: number })[]).sort((a, b) => a.seq - b.seq));
          };
        };
      }),
  );
}

/** A receipt photo kept on the tablet (step 5.2b): IndexedDB "bakery-ops", object store "photos", one record per photo, the blob in it. */
export interface PhotoOnTablet {
  clientUuid: string;
  fileId: number | null;
  /** the blob's own size and type, read from the stored blob */
  size: number;
  type: string;
}

/** The receipt photos the page keeps in IndexedDB (an empty list when the store does not exist yet). */
export function photosOnTablet(page: Page): Promise<PhotoOnTablet[]> {
  return page.evaluate(
    () =>
      new Promise<PhotoOnTablet[]>((resolve, reject) => {
        const open = indexedDB.open("bakery-ops");
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
          const db = open.result;
          if (!db.objectStoreNames.contains("photos")) {
            db.close();
            resolve([]);
            return;
          }
          const read = db.transaction("photos", "readonly").objectStore("photos").getAll();
          read.onerror = () => reject(read.error);
          read.onsuccess = () => {
            db.close();
            const records = read.result as { clientUuid: string; fileId: number | null; blob: Blob }[];
            resolve(records.map((r) => ({ clientUuid: r.clientUuid, fileId: r.fileId, size: r.blob.size, type: r.blob.type })));
          };
        };
      }),
  );
}

/** The orders the API holds with this client_uuid (the account's recent orders, any status). */
export async function ordersWithUuid(api: Api, clientUuid: string): Promise<{ id: number; status: string; lines: unknown[] }[]> {
  const list = await api.get<{ data: { id: number; client_uuid: string; status: string; lines: unknown[] }[] }>("/orders", { limit: 200 });
  return list.data.filter((order) => order.client_uuid === clientUuid);
}
