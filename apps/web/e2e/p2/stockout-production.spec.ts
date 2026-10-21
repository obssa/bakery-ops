import { test } from "@playwright/test";

// Phase 2 gate project p2-stockout-production (manifest phases.2.gate.criteria; docs/phases/phase-2-plan.md section 7).
//
// Placeholder until step 2.8 (web) builds the production board. Phase 2 is in progress, so e2e-p2 requires this
// project to list a test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme, so it is
// reported as skipped and proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 2` from
// closing the phase while the placeholder exists.
//
// TODO(p2): step 2.8 replaces this placeholder with the real flow: the pad sells the last cake, the stock-out
// becomes a production request (waiting for the queue, never a fixed sleep), the baker accepts, starts and finishes
// it, the overseer confirms, and the pad shows the cake available again. Fixture built through the admin API in the
// E2E Test Branch, so it carries @live.

test.describe("p2 stock-out to production", () => {
  test.fixme("a sold-out cake becomes a production request and is back on the pad once confirmed (built in step 2.8)", async () => {
    // step 2.8
  });
});
