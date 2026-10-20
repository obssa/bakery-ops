"use client";

import { useEffect } from "react";

// Registers the shell cache (sw.js is generated at build time by scripts/build-sw.mjs into the static export).
// Only in the production export: `next dev`, `next start` and the Playwright runs (service workers blocked) have none,
// and a failed registration is harmless: the app works the same without the cache.
export function ServiceWorker() {
  useEffect(() => {
    if (process.env.NEXT_PUBLIC_SHELL_CACHE !== "1" || !("serviceWorker" in navigator)) return;
    const base = process.env.NEXT_PUBLIC_BASE_PATH ?? "";
    navigator.serviceWorker.register(`${base}/sw.js`, { scope: `${base}/` }).catch(() => {});
  }, []);
  return null;
}
