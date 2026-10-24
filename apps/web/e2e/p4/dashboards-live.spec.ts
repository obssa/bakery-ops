import { test } from "@playwright/test";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7).
//
// Placeholder of step 4.1a. Phase 4 is in progress, so e2e-p4 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 4` from closing the phase while the placeholder exists.
//
// TODO(p4): 4.6b: run-unique channel, rebuild with sync:true, soft budget 2500 ms.

test.describe("dashboards-live", () => {
  test.fixme("the dashboards show exact figures for a run-unique channel, within the soft budget @live (built in step 4.6b)", async () => {
    // step 4.6b
  });
});
