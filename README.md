# bakery-ops

Bakery Operations Platform for a bakery and café in Addis Ababa: waiter pad, cashier till,
kitchen and bar display screens, production board for bakers, ledger-based inventory with
recipes, per-station shift handover with approved and locked counts, payments by channel with
receipt photos, full audit trail, analytics, Amharic and English.

| Part | Path | Stack |
|---|---|---|
| API | `apps/api` | Laravel 13, PHP 8.3, PostgreSQL 16, Redis 7, Reverb, Horizon, Pest |
| Web | `apps/web` | Next.js 15, TypeScript strict, Tailwind, shadcn/ui, TanStack Query, next-intl, Workbox, Playwright |
| Infra | `compose.yml`, `docker/` | Docker Compose: app, web (Caddy), ws, worker, db, redis, minio |
| Docs | `docs/` | `architecture.md`, ADRs, `phases/STATUS.json`, OpenAPI, ERD |
| Build skill | `.claude/skills/bakery-ops-builder` | Phase-gated build procedure used by Claude Code |

## Delivery

Six phases, each ending in a gate that must be green in CI before the next starts.
See `docs/phases/STATUS.json` for where we are and `.claude/skills/bakery-ops-builder/references/phases/` for what each phase contains.

| Phase | Scope | Gate job |
|---|---|---|
| 0 | Foundations and ledger | `gate-p0` |
| 1 | Ordering and live KDS | `gate-p1` |
| 2 | Inventory and production | `gate-p2` |
| 3 | Shifts and handover | `gate-p3` |
| 4 | Payments and analytics | `gate-p4` |
| 5 | Hardening and Amharic | `gate-p5` |

## Local development (Windows, Docker Desktop)

```bash
cp .env.example .env
make up          # docker compose up -d --wait
make test        # Pest inside the app container
make ci          # everything GitHub Actions runs, locally
make gate PHASE=0
```

PHP is never run on the host. `scripts/php`, `scripts/artisan`, `scripts/composer`, `scripts/pest` wrap `docker compose exec app`.
