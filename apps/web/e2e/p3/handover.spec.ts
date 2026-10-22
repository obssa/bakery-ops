import { test } from "@playwright/test";

// Phase 3 gate project p3-handover (manifest phases.3.gate.criteria; docs/phases/phase-3-plan.md section 7).
//
// Placeholder until step 3.7 (web) builds the shift screens. Phase 3 is in progress, so e2e-p3 requires this project to
// list a test (e2e.yml, "Projects of started phases have tests"); this one is marked fixme, so it is reported as skipped
// and proves nothing yet. The marker below keeps `gate_check.py --mode done --phase 3` from closing the phase while the
// placeholder exists.
//
// TODO(p3): step 3.7 replaces this placeholder with the real flow: the kitchen closing count with one variance needs a
// reason, is submitted, the admin approves it side by side, the count_adjustment shows in the ledger, the count locks and
// shows its hash, and the next shift's opening count is prefilled with the locked values. Fixture built through the admin
// API in the E2E Test Branch, so it carries @live.

test.describe("p3 handover", () => {
  test.fixme("a closing count with a variance is approved, locked and hashed, and the next opening is prefilled (built in step 3.7)", async () => {
    // step 3.7
  });
});
