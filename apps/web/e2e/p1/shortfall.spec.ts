import { test } from "@playwright/test";

// Phase 1 gate project p1-shortfall (manifest phases.1.gate.criteria; docs/phases/phase-1-plan.md section 7).
//
// Placeholder until step 1.6 (web) builds the pad. Phase 1 is in progress, so e2e-p1 requires this project to list a
// test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme, so it is reported as skipped and
// proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 1` from closing the phase while the
// placeholder exists.
//
// TODO(p1): step 1.6 replaces this placeholder with the real flow: on the pad, ordering more than is available shows
// the shortfall inline on the exact line (ingredient, quantity short, station; in English and in Amharic) and nothing
// is sent; reducing the line sends. Fixture built through the admin API in the E2E Test Branch, so it carries @live.

test.describe("p1 shortfall", () => {
  test.fixme("selling more than is available shows the shortfall on the exact line (built in step 1.6)", async () => {
    // step 1.6
  });
});
