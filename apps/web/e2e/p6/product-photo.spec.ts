import { test } from "@playwright/test";

// Phase 6 gate project p6-product-photo (docs/phases/phase-6-plan.md, steps 6.3a and 6.4b and section 7).
//
// Placeholder of the plan PR. Phase 6 is in progress, so e2e-p6 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 6` from closing the phase while the placeholder exists.
//
// TODO(p6): 6.4b: the admin drops a photo, frames it to 4:3 and saves it; the pad card and the order line show it; replace; remove; the placeholder is back.

test.describe("product photo", () => {
  test.fixme("an admin frames and saves a product photo, the pad shows it, and replace and remove work (built in step 6.4b)", async () => {
    // step 6.4b
  });
});
