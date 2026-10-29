import { test } from "@playwright/test";

// Phase 5 gate project p5-device (docs/phases/phase-5-plan.md, step 5.1b and section 7).
//
// Placeholder of the plan PR. Phase 5 is in progress, so e2e-p5 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 5` from closing the phase while the placeholder exists.
//
// TODO(p5): 5.1b: an unknown tablet sees the not-enrolled screen on the pad, KDS and till, admin pages still work, enrol in place, revoke.

test.describe("device", () => {
  test.fixme("an unknown tablet is refused on the station screens and works once an admin enrols it in place (built in step 5.1b)", async () => {
    // step 5.1b
  });
});
