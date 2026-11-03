"use client";

import { useEffect, useRef, useState, type RefObject } from "react";

// Whether an element has come near the window yet (step 6.4a): the catalog list asks a product's price (GET /items/{item}/prices) only for
// the rows and cards a person can see, so a branch with thousands of items costs a read per row in view, not one per item. One shared
// IntersectionObserver for every row, 300 px ahead of the window; once seen, an element stays seen. Without IntersectionObserver
// (an old browser) everything counts as seen at once.

const MARGIN = "300px";
const waiting = new Map<Element, () => void>();
let observer: IntersectionObserver | null = null;

function watch(element: Element, onSeen: () => void): () => void {
  if (typeof IntersectionObserver === "undefined") {
    onSeen();
    return () => undefined;
  }
  observer ??= new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const seen = waiting.get(entry.target);
        waiting.delete(entry.target);
        observer?.unobserve(entry.target);
        seen?.();
      }
    },
    { rootMargin: MARGIN },
  );
  waiting.set(element, onSeen);
  observer.observe(element);
  return () => {
    waiting.delete(element);
    observer?.unobserve(element);
  };
}

/** A ref to put on an element, and whether it has been in (or near) the window. */
export function useSeen<T extends Element>(): [RefObject<T | null>, boolean] {
  const ref = useRef<T | null>(null);
  const [seen, setSeen] = useState(false);
  useEffect(() => {
    const element = ref.current;
    if (seen || !element) return;
    return watch(element, () => setSeen(true));
  }, [seen]);
  return [ref, seen];
}
