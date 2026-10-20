import { test } from "@playwright/test";

// Phase 1 gate project p1-order-flow (manifest phases.1.gate.criteria; docs/phases/phase-1-plan.md section 7).
//
// Placeholder until step 1.6 (web) builds /pad, /kds/kitchen, /kds/bar and /till. Phase 1 is in progress, so e2e-p1
// requires this project to list a test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme, so
// it is reported as skipped and proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 1`
// from closing the phase while the placeholder exists.
//
// TODO(p1): step 1.6 replaces this placeholder with the real flow, four browser contexts (pad, kitchen KDS, bar KDS,
// till): short code -> table -> chicken sandwich + latte + one display-case product -> Send -> kitchen and bar tickets
// visible within 5 s through the configured live-update transport (polling: only tickets?since= requests with
// If-None-Match after the initial load) -> accept -> ready -> pad ready badge -> served -> till cash pay -> closed;
// post-conditions through the API (reservation, production_consume for the station lines; reservation, sale for the
// display-case line; available = stocked minus sold). Fixtures built through the admin API in the E2E Test Branch,
// so the flow carries @live.

test.describe("p1 order flow", () => {
  test.fixme("pad to kitchen and bar KDS to ready, served, cash paid and closed (built in step 1.6)", async () => {
    // step 1.6
  });
});
