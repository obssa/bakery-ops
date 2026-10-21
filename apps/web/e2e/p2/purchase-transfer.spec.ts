import { test } from "@playwright/test";

// Phase 2 gate project p2-purchase-transfer (manifest phases.2.gate.criteria; docs/phases/phase-2-plan.md section 7).
//
// Placeholder until step 2.8 (web) builds the purchase and transfer screens. Phase 2 is in progress, so e2e-p2
// requires this project to list a test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme,
// so it is reported as skipped and proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 2`
// from closing the phase while the placeholder exists.
//
// TODO(p2): step 2.8 replaces this placeholder with the real flow: the admin records and confirms a purchase, the
// store balance and the 4 dp moving average follow, the chef requests a transfer from the kitchen stock strip, the
// manager confirms it and both balances move; a second request is rejected and nothing moves. Fixture built through
// the admin API in the E2E Test Branch, so it carries @live.

test.describe("p2 purchase and transfer", () => {
  test.fixme("a purchase moves the moving average and a confirmed transfer moves stock (built in step 2.8)", async () => {
    // step 2.8
  });
});
