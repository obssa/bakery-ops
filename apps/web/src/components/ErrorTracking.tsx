"use client";

import { useEffect } from "react";

// Phase 5 step 5.5a (ADR 0021): the browser's error tracking, loaded lazily and only when NEXT_PUBLIC_SENTRY_DSN was set at build time (a
// GitHub variable read by deploy.yml; next.config.ts makes it "" when unset). Unset, the condition below is the constant "", the compiler
// drops the branch with its import(), and the Sentry SDK is not in the bundle at all: nothing is loaded and nothing is sent.
export function ErrorTracking() {
  useEffect(() => {
    if (process.env.NEXT_PUBLIC_SENTRY_DSN) {
      const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;
      import("@/lib/errorTracking/sentry").then((module) => module.startErrorTracking(dsn)).catch(() => {});
    }
  }, []);
  return null;
}
