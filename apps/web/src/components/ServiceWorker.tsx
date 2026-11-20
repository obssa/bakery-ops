"use client";

import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { useOutbox } from "@/lib/outbox/store";
import { isChunkLoadError, mayAutoReload, mayLoadUpdate, mayReloadStale, type Waiting } from "@/lib/swUpdate";
import { buttonClass } from "./styles";

// Registers the shell cache (sw.js is generated at build time by scripts/build-sw.mjs into the static export).
// Only in the production export: `next dev`, `next start` and the Playwright runs (service workers blocked) have none,
// and a failed registration is harmless: the app works the same without the cache.
//
// 2026-11-20 (lib/swUpdate.ts): a new version's worker no longer takes over open tabs by itself. It waits; this tab says a new version
// is ready and offers Reload once nothing waits to be sent, and only then hands over and loads again. A part of the page that cannot load
// (a file a deploy removed) reloads the page by itself, once, when nothing waits and the connection is up; else the tab asks for a reload.

/** How often an open tab asks the server for a new version (a tablet may run one page for days without navigating). */
const UPDATE_CHECK_MS = 30 * 60 * 1000;
/** Where the page notes its own reload for a missing part (sessionStorage), so a part still missing never reloads it in a loop. */
const RELOADED_AT = "bakery-ops.chunk-reload-at";

function readReloadedAt(): number | null {
  try {
    const value = window.sessionStorage.getItem(RELOADED_AT);
    return value === null ? null : Number(value);
  } catch {
    return null;
  }
}

function noteReload(now: number): void {
  try {
    window.sessionStorage.setItem(RELOADED_AT, String(now));
  } catch {
    // without storage the page may reload once more; it never loops faster than a page load
  }
}

export function ServiceWorker() {
  const t = useTranslations("shell");
  const [waitingWorker, setWaitingWorker] = useState<ServiceWorker | null>(null);
  const [stale, setStale] = useState(false);
  const queued = useOutbox((s) => s.queue.length);
  const durable = useOutbox((s) => s.durable);
  const waiting: Waiting = { queued, durable };
  const waitingRef = useRef(waiting);
  waitingRef.current = waiting;

  useEffect(() => {
    if (process.env.NEXT_PUBLIC_SHELL_CACHE !== "1" || !("serviceWorker" in navigator)) return;
    const base = process.env.NEXT_PUBLIC_BASE_PATH ?? "";
    let timer: ReturnType<typeof setInterval> | null = null;
    let cancelled = false;
    navigator.serviceWorker
      .register(`${base}/sw.js`, { scope: `${base}/` })
      .then((registration) => {
        if (cancelled) return;
        // a worker of a newer version waits only when this page is already controlled (the first install has nothing to update)
        const offer = (worker: ServiceWorker | null) => {
          if (worker && navigator.serviceWorker.controller) setWaitingWorker(worker);
        };
        offer(registration.waiting);
        registration.addEventListener("updatefound", () => {
          const installing = registration.installing;
          installing?.addEventListener("statechange", () => {
            if (installing.state === "installed") offer(registration.waiting ?? installing);
          });
        });
        timer = setInterval(() => void registration.update().catch(() => {}), UPDATE_CHECK_MS);
      })
      .catch(() => {});
    // the new worker took over (after Reload): load the page from it
    let reloading = false;
    const onController = () => {
      if (reloading) return;
      reloading = true;
      window.location.reload();
    };
    navigator.serviceWorker.addEventListener("controllerchange", onController);
    return () => {
      cancelled = true;
      if (timer) clearInterval(timer);
      navigator.serviceWorker.removeEventListener("controllerchange", onController);
    };
  }, []);

  // a part of the page that cannot load (a file of an older version, gone since a deploy)
  useEffect(() => {
    const onFailure = (reason: unknown) => {
      if (!isChunkLoadError(reason)) return;
      const now = Date.now();
      const online = typeof navigator === "undefined" || navigator.onLine !== false;
      // offline, a part that does not load says nothing about the version: the connection is down (the offline screens say so)
      if (!online) return;
      if (mayAutoReload({ waiting: waitingRef.current, online, lastReloadAt: readReloadedAt(), now })) {
        noteReload(now);
        window.location.reload();
        return;
      }
      setStale(true);
    };
    const onError = (event: ErrorEvent) => onFailure(event.error ?? event.message);
    const onRejection = (event: PromiseRejectionEvent) => onFailure(event.reason);
    window.addEventListener("error", onError);
    window.addEventListener("unhandledrejection", onRejection);
    return () => {
      window.removeEventListener("error", onError);
      window.removeEventListener("unhandledrejection", onRejection);
    };
  }, []);

  if (!waitingWorker && !stale) return null;
  const allowed = stale ? mayReloadStale(waiting) : mayLoadUpdate(waiting);
  const reload = () => {
    if (!allowed) return;
    if (waitingWorker) {
      // the waiting worker takes over; controllerchange reloads the page
      waitingWorker.postMessage({ type: "SKIP_WAITING" });
      if (stale) window.location.reload();
    } else {
      window.location.reload();
    }
  };
  return (
    <div role="status" aria-live="polite" className="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-xl flex-wrap items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 shadow-float">
      <div className="min-w-0 flex-1 space-y-1 text-base text-neutral-900">
        <p className="font-semibold">{stale ? t("update_stale") : t("update_ready")}</p>
        {allowed ? null : <p className="text-neutral-700">{t("update_wait")}</p>}
      </div>
      <button type="button" disabled={!allowed} onClick={reload} className={buttonClass({ variant: "primary" })}>
        {t("update_reload")}
      </button>
    </div>
  );
}
