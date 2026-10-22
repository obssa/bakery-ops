import { test } from "@playwright/test";

// Phase 3 gate project p3-opening-prefill (manifest phases.3.gate.criteria; docs/phases/phase-3-plan.md section 7).
//
// Placeholder until step 3.7 (web) builds the shift screens. Phase 3 is in progress, so e2e-p3 requires this project to
// list a test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme, so it is reported as skipped
// and proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 3` from closing the phase while the
// placeholder exists.
//
// TODO(p3): step 3.7 replaces this placeholder with the real flow: the opening count of the Night template, started
// after midnight, is prefilled with the previous locked closing and the shift carries the local date it started on.
// Fixture built through the admin API in the E2E Test Branch, so it carries @live.

test.describe("p3 opening prefill", () => {
  test.fixme("the opening count across midnight on the Night template is prefilled from the locked closing (built in step 3.7)", async () => {
    // step 3.7
  });
});
