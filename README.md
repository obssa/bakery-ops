# bakery-ops

Bakery Operations Platform for a bakery and café in Addis Ababa: waiter pad, cashier till,
kitchen and bar display screens, production board for bakers, ledger-based inventory with
recipes, per-station shift handover with approved and locked counts, payments by channel with
receipt photos, full audit trail, analytics, Amharic and English.

| Part | Path | Stack |
|---|---|---|
| API | `apps/api` | Laravel 13, PHP 8.3, MariaDB 10.11 (live), Pest; Redis/Reverb/Horizon on a VPS later |
| Web | `apps/web` | Next.js 15, TypeScript strict, Tailwind, shadcn/ui, TanStack Query, next-intl, Workbox, Playwright |
| Infra | `compose.yml`, `docker/`, `.github/workflows/deploy.yml` | Live: https://bakery-ops.orcamind.dev (cPanel, deployed from `main`); Docker Compose for CI e2e and local debugging |
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

## Demo bakery

A realistic bakery-cafe in Addis Ababa, seeded phase by phase into a branch of its own ("Demo Bakery", Africa/Addis_Ababa,
ETB) and never into another branch: six stations, seven stock locations, 14 categories, 43 ingredients and 28 products
(made to order, made in batches, bought in) with ETB prices, v1 recipes and opening stock written through the ledger.
`php artisan db:seed` loads it locally (CI's e2e job: `SEED_PHASE=<n> ... --seeder=DemoSeeder`); on a server,
`php artisan bakery:seed-demo --force` (see `docs/runbook/server.md`). Running it again only fills in what is missing.

| Role | Name | Phone | Language | Stations |
|---|---|---|---|---|
| Admin | Dawit Bekele (ዳዊት በቀለ) | +251900000101 | English | all (admin) |
| Manager | Meron Tadesse (ሜሮን ታደሰ) | +251900000102 | English | Store (counts) |
| Cashier | Selam Getachew (ሰላም ጌታቸው) | +251900000103 | Amharic | Till (counts) |
| Waiter | Yonas Alemu (ዮናስ አለሙ) | +251900000104 | Amharic | (shared pad) |
| Waiter | Hana Worku (ሐና ወርቁ) | +251900000105 | Amharic | (shared pad) |
| Chef | Biniam Haile (ቢንያም ኃይሌ) | +251900000106 | Amharic | Kitchen (counts) |
| Barista | Tigist Mekonnen (ትዕግሥት መኮንን) | +251900000107 | Amharic | Bar (counts) |
| Cake baker | Hirut Desta (ሂሩት ደስታ) | +251900000108 | Amharic | Cake room (counts), Bakery (overseer) |
| Bread baker | Abebe Girma (አበበ ግርማ) | +251900000109 | Amharic | Bakery (counts) |
| Pad (Phase 1) | Pad 1 (ፓድ 1) | +251900000110 | English | the shared pad's own account, no short code |

The phone numbers are made up. Every demo account signs in with its phone and the demo password: `BAKERY_DEMO_PASSWORD`
from the server `.env` (required outside local and testing; locally and in CI the documented default in
`apps/api/database/seeders/Demo/DemoCredentials.php`). Short codes are derived from that password; `bakery:seed-demo`
prints them once, when it creates the accounts, and an admin can reset one under People.
Phase 1 adds 12 dining tables, the eight payment channels, the shared pad (device "Front pad", account "Pad 1") and
today's seven sample orders at different stages (see the runbook).

## Local development (Windows, Docker Desktop)

```bash
cp .env.example .env
make up          # docker compose up -d --wait
make test        # Pest inside the app container
make ci          # everything GitHub Actions runs, locally
make gate PHASE=0
```

PHP is never run on the host. `scripts/php`, `scripts/artisan`, `scripts/composer`, `scripts/pest` wrap `docker compose exec app`.
