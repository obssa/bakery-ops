import * as Sentry from "@sentry/browser";
import { isRefusal, scrubEvent } from "./scrub";

// Phase 5 step 5.5a (ADR 0021). The only module that imports the Sentry browser SDK, and it is only ever loaded by a dynamic import that
// exists in the bundle when NEXT_PUBLIC_SENTRY_DSN was set at build time (components/ErrorTracking.tsx): a build without the DSN carries
// no SDK at all (web-build and web-errors check the built files).
//
// Errors only: no tracing, no replay, no session (release health) or client-report envelopes, no personal data (the SDK collects no user,
// cookie, body, frame variable or header but the browser's name: SDK 11's dataCollection, which replaced sendDefaultPii; and scrubEvent
// runs on every event), and an API refusal or a lost connection is never sent (isRefusal).

export function startErrorTracking(dsn: string): void {
  Sentry.init({
    dsn,
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: { request: { allow: ["User-Agent"] }, response: false },
      httpBodies: [],
      stackFrameVariables: false,
      databaseQueryData: false,
      queues: false,
      graphQL: { document: false, variables: false },
      genAI: { inputs: false, outputs: false },
    },
    sendClientReports: false,
    integrations: (defaults) => defaults.filter((integration) => integration.name !== "BrowserSession"),
    beforeSend(event, hint) {
      if (isRefusal(hint.originalException)) return null;
      return scrubEvent(event);
    },
  });
  // a plain mark the p5-errors-on flow waits for (it says only that error tracking started on this page)
  document.documentElement.dataset.errorTracking = "on";
}
