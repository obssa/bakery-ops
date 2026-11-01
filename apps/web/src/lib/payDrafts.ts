"use client";

import { create } from "zustand";
import type { PayRow, RowPhoto } from "./paySheet";

// The pay sheets of this till that are not finished yet, one per order, kept in memory while the page lives (step 4.5b). Closing the sheet
// (a tap beside it, Escape, a network failure) keeps the rows as they were typed, the photos already on the server and the batch's
// client_uuid and Idempotency-Key, so the cashier picks up where they left off and a retry is the same write: the API replays a batch it
// recorded instead of taking the money twice. A completed sheet is dropped. Nothing is written to storage: a reload starts afresh, and a
// photo uploaded but never attached is removed by the server's orphan purge (files:purge-orphans, 24 h). A completed batch that cannot be sent
// joins the outbox (step 5.2a, lib/outbox/store.ts); the photo queue is step 5.2b.

export interface PayDraft {
  /** the batch's client_uuid (stored by the API as every payment's batch_uuid) */
  batchUuid: string;
  rows: PayRow[];
  /** the Idempotency-Key of the last Complete and the body it was made for; null after a refusal (a refusal wrote nothing) */
  attempt: { fingerprint: string; key: string } | null;
}

interface PayDrafts {
  drafts: Record<number, PayDraft>;
  put: (orderId: number, draft: PayDraft) => void;
  rows: (orderId: number, update: (rows: PayRow[]) => PayRow[]) => void;
  photo: (orderId: number, rowId: string, update: (photo: RowPhoto) => RowPhoto) => void;
  drop: (orderId: number) => void;
}

export const usePayDrafts = create<PayDrafts>()((set) => ({
  drafts: {},
  put: (orderId, draft) => set((s) => ({ drafts: { ...s.drafts, [orderId]: draft } })),
  rows: (orderId, update) =>
    set((s) => {
      const draft = s.drafts[orderId];
      return draft ? { drafts: { ...s.drafts, [orderId]: { ...draft, rows: update(draft.rows) } } } : s;
    }),
  photo: (orderId, rowId, update) =>
    set((s) => {
      const draft = s.drafts[orderId];
      if (!draft) return s;
      const rows = draft.rows.map((row) => (row.id === rowId ? { ...row, photo: update(row.photo) } : row));
      return { drafts: { ...s.drafts, [orderId]: { ...draft, rows } } };
    }),
  drop: (orderId) =>
    set((s) => {
      const drafts = { ...s.drafts };
      delete drafts[orderId];
      return { drafts };
    }),
}));

/** The picture behind a row's photo (the shrunk JPEG and its object URL for the thumbnail), kept for a retry of its upload. */
const pictures = new Map<string, { blob: Blob; url: string | null }>();

export function keepPicture(rowId: string, blob: Blob): string | null {
  forgetPicture(rowId);
  const url = typeof URL.createObjectURL === "function" ? URL.createObjectURL(blob) : null;
  pictures.set(rowId, { blob, url });
  return url;
}

export function pictureOf(rowId: string): { blob: Blob; url: string | null } | undefined {
  return pictures.get(rowId);
}

export function forgetPicture(rowId: string): void {
  const kept = pictures.get(rowId);
  if (kept?.url) URL.revokeObjectURL(kept.url);
  pictures.delete(rowId);
}
