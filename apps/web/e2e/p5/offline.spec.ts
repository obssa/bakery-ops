import { test } from "@playwright/test";

// Phase 5 gate project p5-offline (docs/phases/phase-5-plan.md, steps 5.2a to 5.2c and section 7).
//
// Placeholder of the plan PR. Phase 5 is in progress, so e2e-p5 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 5` from closing the phase while the placeholder exists.
//
// TODO(p5): 5.2a: an order sent while offline replays once when online and the server holds exactly one order with its client_uuid.

test.describe("offline", () => {
  test.fixme("an order queued offline replays exactly once when the tablet is online again (built in step 5.2a)", async () => {
    // step 5.2a
  });
});
