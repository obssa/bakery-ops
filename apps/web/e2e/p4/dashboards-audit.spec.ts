import { test } from "@playwright/test";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7).
//
// Placeholder of step 4.1a. Phase 4 is in progress, so e2e-p4 requires this project to list a test (e2e.yml, "Projects of started
// phases have tests"); this one is marked fixme, so it is reported as skipped and proves nothing yet. The marker below keeps
// `gate_check.py --mode done --phase 4` from closing the phase while the placeholder exists.
//
// TODO(p4): 4.6b: after the refund spec's order.

test.describe("dashboards-audit", () => {
  test.fixme("the audit screen shows this run's refund with its approver @live (built in step 4.6b)", async () => {
    // step 4.6b
  });
});
