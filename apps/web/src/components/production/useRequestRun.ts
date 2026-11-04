"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { useIdempotencyKey } from "@/lib/api/idempotency";

// One write of a production request (accept, start, done, confirm, send back, cancel), shared by the board card's one action and the
// request card's buttons (step 6.3c): busy while it runs, the refusal kept to show inline, one Idempotency-Key per logical write reused
// by a retry (ADR 0003), and the card and the board read again once the server has it. A write the outbox queued offline (start and
// done, step 5.2a) changed nothing on the server yet: the board is read again when the outbox has sent it.

export interface RequestRun {
  /** the write in flight, by its kind */
  busy: string | null;
  error: unknown;
  setError: (error: unknown) => void;
  /** runs one write; true when the server took it or the outbox kept it */
  run: (kind: string, payload: unknown, call: (key: string) => Promise<unknown>) => Promise<boolean>;
}

export function useRequestRun(requestId: number, onChanged: () => void): RequestRun {
  const queryClient = useQueryClient();
  const keys = useIdempotencyKey();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);

  async function run(kind: string, payload: unknown, call: (key: string) => Promise<unknown>): Promise<boolean> {
    setBusy(kind);
    setError(null);
    try {
      const outcome = await call(keys.keyFor({ kind, id: requestId, payload }));
      keys.done();
      // queued by the outbox (offline): nothing changed on the server yet
      if (outcome !== null && typeof outcome === "object" && (outcome as { sent?: unknown }).sent === false) return true;
      await queryClient.invalidateQueries({ queryKey: ["production-card", requestId] });
      onChanged();
      return true;
    } catch (failure) {
      setError(failure);
      return false;
    } finally {
      setBusy(null);
    }
  }

  return { busy, error, setError, run };
}
