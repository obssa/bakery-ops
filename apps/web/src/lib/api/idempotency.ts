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
 * After a failed write, is its outcome unknown (ADR 0030)? Only then may a retry reuse its Idempotency-Key: no answer (a network failure
 * or a timeout, status 0), a server error (5xx, which the API never stores) or the same key still running (idempotency.in_flight). The
 * write may have happened, so the retry must replay it. Any other answer is definite: a refusal (4xx) wrote nothing, and the API stores it
 * and would replay it under the same key even after its cause is gone (a shift started, stock bought), so the next try takes a new key.
 * Something that is not an API answer at all (a bug before the call) counts as unknown: keeping the key can never write twice.
 */
export function outcomeUnknown(error: unknown): boolean {
  if (typeof error !== "object" || error === null || !("status" in error)) return true;
  const { status, key } = error as { status: unknown; key?: unknown };
  if (typeof status !== "number") return true;
  if (status === 0 || status >= 500) return true;
  return key === "idempotency.in_flight";
}

/**
 * The key of one logical write, by its payload (ADR 0003, ADR 0030). The same payload gets the same key while the last outcome is
 * unknown, so a retry after a lost answer or a double tap replays the first answer instead of writing twice. A changed payload always gets
 * a new key (the API refuses a known key with another body). A definite answer ends the attempt: done() after a success, failed(error)
 * after a refusal, so the next tap, even with the same payload, is a new request that the server checks again.
 *
 * The payload given is what the server's fingerprint covers: leave a PIN out, as the API does (its SECRET_FIELDS), so retyping the PIN
 * after an unknown outcome is still the same write.
 */
export class KeySlot {
  private last: { fingerprint: string; key: string } | null = null;

  keyFor(payload: unknown): string {
    const fingerprint = JSON.stringify(payload);
    if (this.last?.fingerprint !== fingerprint) this.last = { fingerprint, key: uuid() };
    return this.last.key;
  }

  /** The write was answered: the next one is new. */
  done(): void {
    this.last = null;
  }

  /** The write failed: the key is kept only while the outcome is unknown. */
  failed(error: unknown): void {
    if (!outcomeUnknown(error)) this.last = null;
  }
}

/**
 * Named keys for a screen with several writes (the production board's moves, a station's shift actions): the same name gets the same key
 * while the last outcome under it is unknown; done(name) and failed(name, error) end the attempt as KeySlot does.
 */
export class KeyRing {
  private readonly keys = new Map<string, string>();

  keyFor(name: string): string {
    let key = this.keys.get(name);
    if (!key) {
      key = uuid();
      this.keys.set(name, key);
    }
    return key;
  }

  done(name: string): void {
    this.keys.delete(name);
  }

  failed(name: string, error: unknown): void {
    if (!outcomeUnknown(error)) this.keys.delete(name);
  }
}

/**
 * One Idempotency-Key per logical write, for a form (a KeySlot kept across renders): keyFor(payload) when sending, done() after a
 * success, failed(error) after a failure (it may be passed straight as a mutation's onError).
 */
export function useIdempotencyKey() {
  const slot = useRef<KeySlot | null>(null);

  const keyFor = useCallback((payload: unknown): string => (slot.current ??= new KeySlot()).keyFor(payload), []);
  const done = useCallback(() => slot.current?.done(), []);
  const failed = useCallback((error: unknown) => slot.current?.failed(error), []);

  return { keyFor, done, failed };
}

/** A KeyRing kept across renders. */
export function useKeyRing(): KeyRing {
  const ring = useRef<KeyRing | null>(null);
  ring.current ??= new KeyRing();
  return ring.current;
}
