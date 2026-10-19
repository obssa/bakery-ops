import { useCallback, useRef } from "react";

/** A v4 UUID; crypto.randomUUID where the browser offers it (secure contexts), getRandomValues otherwise. */
export function uuid(): string {
  const c = globalThis.crypto;
  if (typeof c?.randomUUID === "function") return c.randomUUID();
  const b = new Uint8Array(16);
  c.getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * One Idempotency-Key per logical write (ADR 0003): submitting the same payload again (a retry after a timeout or a
 * double tap) reuses the key, so the API replays the first answer instead of writing twice; a changed payload gets
 * a new key, because the API refuses a known key with a different body. After a success, call done() so the next
 * write, even with an identical payload, is a new one.
 */
export function useIdempotencyKey() {
  const last = useRef<{ fingerprint: string; key: string } | null>(null);

  const keyFor = useCallback((payload: unknown): string => {
    const fingerprint = JSON.stringify(payload);
    if (last.current?.fingerprint !== fingerprint) last.current = { fingerprint, key: uuid() };
    return last.current.key;
  }, []);

  const done = useCallback(() => {
    last.current = null;
  }, []);

  return { keyFor, done };
}
