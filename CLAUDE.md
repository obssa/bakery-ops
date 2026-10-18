# bakery-ops

Bakery Operations Platform: POS + kitchen display + bakery production + ledger inventory + shift handover,
for a bakery in Addis Ababa. Laravel 13 API (`apps/api`) + Next.js 15 PWA (`apps/web`), PostgreSQL 16, Redis 7.

## How work happens here

- **Always work through the `bakery-ops-builder` skill** (`.claude/skills/bakery-ops-builder/SKILL.md`).
  It knows the phase order, the module dependency graph, the gates and the CI layout.
  Any request to build, continue, plan, test or check CI for this project goes through it.
- **Never start a phase or a module without `python scripts/gate_check.py --mode entry`** passing.
  A gate that is red upstream means the work you are about to do is built on sand.
- **Contract tests first.** Before writing a consumer module, write the tests that prove the provider
  behaves the way the consumer needs, and get them green with zero consumer code.
- **`make ci` before every PR.** It runs the same steps as GitHub Actions, inside Docker.
- **PHP runs only inside Docker.** The host has PHP 8.0; Laravel 13 needs 8.3. Use `scripts/php`,
  `scripts/artisan`, `scripts/composer`, `scripts/pest` (they `docker compose exec` into `app`).
- Phase state lives in `docs/phases/STATUS.json`. `plan_approved: true` is set only by Ramin's own commit.
- Source of truth for the architecture: `docs/architecture.md` (sections 1 to 13) and the skill's references.

## Non-negotiables (from the architecture document)

1. Inventory is an append-only ledger; `stock_movements` is the only way stock changes.
2. Two inventories, one `items` table (`kind = ingredient | product`); recipes link them.
3. Orders are stock-gated in one transaction with row locks; consumption is recorded at READY, not at send.
4. Every write records who, acting-as, shift, station, device, when. Corrections are new rows. Soft deletes only.
5. Shifts are per station; counts are confirmed, explained, approved, locked and hash-chained.
6. Permissions enforced server-side on every endpoint.
7. Idempotency-Key + client UUID on every write.
8. `branch_id` on every tenant table.
9. Everything user-facing is bilingual (`_en`, `_am`).
10. NUMERIC for money and quantities, never float.
