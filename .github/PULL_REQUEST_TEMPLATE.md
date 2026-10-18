## Phase / module

Phase: `N` · Step: `N.x <module>` · Branch: `phase-N/<module>`

## What this PR builds

One paragraph. Which capabilities from the manifest `provides` list are now real.

## Dependencies proven first

- [ ] Contract tests for every provider this module consumes were committed **before** consumer code
      (`tests/Contract/<Consumer>/<Provider>ContractTest.php`, no `->todo()` left)
- [ ] `gate_check.py --mode entry` passed at the start of this work (paste the summary line)

## Tests added

- Unit / state machines:
- Feature (one per endpoint, 403 per role, idempotent replay):
- Property / concurrency:
- Playwright:
- Registries updated: `tests/Support/write_endpoints.php`, `tests/Support/route_permissions.php`

## Invariants

- [ ] NUMERIC scales (12,2 money / 14,4 qty), no floats
- [ ] `branch_id`, actor stamp, soft deletes / append-only as the table requires
- [ ] bilingual `_en` / `_am` on user-facing text
- [ ] `make ci` (or `python scripts/task.py ci`) green locally

## Decisions and assumptions

ADR(s) written: `docs/adr/NNNN-*.md` or "none: no architectural decision in this PR".
Assumptions taken instead of asking (safer default stated):

## Gate impact

Does this PR change anything an earlier phase's gate relies on (seeds, contracts, shared classes)? If so, how was that verified?
