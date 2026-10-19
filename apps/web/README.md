# apps/web: the Bakery Ops web shell

Next.js 15 (App Router) + next-intl + Tailwind 4, built as a **static export** and served by LiteSpeed under `/app/`
on the API's own origin (https://bakery-ops.orcamind.dev/app/, ADR 0006). There is no Node server in production.

- Routes: `/app/` (start page: picks the language), `/app/en/...` and `/app/am/...` (both prerendered). Record pages
  use query strings (`?id=`), never dynamic segments, so every page exists as a file.
- API: `src/lib/api` (types from `docs/openapi.json`, decimal strings, an `Idempotency-Key` on every write), bearer
  token in memory + `localStorage` (`src/lib/session.ts`, ADR 0007).
- Dictionaries: `messages/en.json` and `messages/am.json` hold the same keys (`scripts/check_invariants.py --i18n`).
- Font: Noto Sans Ethiopic, self-hosted through `@fontsource-variable/noto-sans-ethiopic`.

```bash
pnpm install
pnpm lint && pnpm exec tsc --noEmit
pnpm build                          # next start build (CI e2e), API at NEXT_PUBLIC_API_URL (default http://localhost:8000)
NEXT_OUTPUT=export pnpm build       # what deploy.yml ships: out/ -> apps/api/public/app/ (same-origin API)
pnpm start -p 3000                  # http://localhost:3000/app/
```

Playwright: `pnpm exec playwright test --project=p0-admin-smoke` against the compose API (`python scripts/task.py up`)
with `E2E_ADMIN_PHONE` / `E2E_ADMIN_PASSWORD` of an admin made by `php artisan bakery:create-admin`; with
`PLAYWRIGHT_BASE_URL=https://bakery-ops.orcamind.dev` the `@live` tests run against the deployed site.
