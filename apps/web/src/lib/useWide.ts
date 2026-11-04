"use client";

import { useSyncExternalStore } from "react";

// Whether the window is at least 1024 px wide (Tailwind's `lg`): the width from which a station screen lays its zones side by side, as the
// admin frame's sidebar does (AdminFrame). Before hydration, and where matchMedia is missing, it answers false: the narrow layout.

const QUERY = "(min-width: 1024px)";

function subscribe(onChange: () => void): () => void {
  if (typeof window === "undefined" || !window.matchMedia) return () => {};
  const list = window.matchMedia(QUERY);
  list.addEventListener("change", onChange);
  return () => list.removeEventListener("change", onChange);
}

export function useWide(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => (typeof window !== "undefined" && !!window.matchMedia ? window.matchMedia(QUERY).matches : false),
    () => false,
  );
}
