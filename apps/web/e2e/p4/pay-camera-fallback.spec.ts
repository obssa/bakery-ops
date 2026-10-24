import { test } from "@playwright/test";

// Phase 4 gate project p4-pay-photo-split (docs/phases/phase-4-plan.md section 7).
//
// Placeholder of step 4.1a. Phase 4 is in progress, so e2e-p4 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 4` from closing the phase while the placeholder exists.
//
// TODO(p4): 4.6b: setInputFiles on the real input.

test.describe("pay-camera-fallback", () => {
  test.fixme("without a camera the pay sheet falls back to the file input and still attaches the photo (built in step 4.6b)", async () => {
    // step 4.6b
  });
});
