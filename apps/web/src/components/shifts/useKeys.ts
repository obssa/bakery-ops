"use client";

import { useCallback, useRef } from "react";
import { uuid } from "@/lib/api/idempotency";

/**
 * One Idempotency-Key per logical write (ADR 0003): the same name (the action and its payload) gets the same key until `done` is
 * called, so a retry after a lost connection answers the first write instead of making a second; a changed payload is a new name.
 */
export function useKeys() {
  const keys = useRef<Map<string, string>>(new Map());
  const keyFor = useCallback((name: string): string => {
    let key = keys.current.get(name);
    if (!key) {
      key = uuid();
      keys.current.set(name, key);
    }
    return key;
  }, []);
  const done = useCallback((name: string) => {
    keys.current.delete(name);
  }, []);
  return { keyFor, done };
}
