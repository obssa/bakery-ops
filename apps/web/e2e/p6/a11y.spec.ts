import { test } from "@playwright/test";

// Phase 6 gate project p6-a11y (docs/phases/phase-6-plan.md, steps 6.2b to 6.4b and section 7).
//
// Placeholder of the plan PR. Phase 6 is in progress, so e2e-p6 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 6` from closing the phase while the placeholder exists.
//
// TODO(p6): 6.2b: axe (@axe-core/playwright, pinned) finds no serious or critical violation on the login screen in en and am; each later step adds its screens.

test.describe("a11y", () => {
  test.fixme("the restyled screens have no serious or critical axe violation in en and am (built from step 6.2b)", async () => {
    // step 6.2b
  });
});
