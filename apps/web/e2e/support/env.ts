// What the end-to-end flows read from the environment (see e2e/p0/admin-smoke.spec.ts for how each environment
// provides it): the API origin, the admin of the isolated "E2E Test Branch", and a run-unique suffix for the names a
// flow creates. Shared by the three step 1.6 specs through e2e/support/.

export const live = !!process.env.PLAYWRIGHT_BASE_URL;

export const apiOrigin = (process.env.NEXT_PUBLIC_API_URL || process.env.PLAYWRIGHT_BASE_URL || "http://localhost:8000").replace(/\/+$/, "");

export const admin = {
  phone: process.env.E2E_ADMIN_PHONE ?? "",
  password: process.env.E2E_ADMIN_PASSWORD ?? "",
};

/** Unique per process, so a retry or a second run never meets the first one's rows. */
export const run = `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;

/**
 * Returns the admin credentials, or tells the caller why a flow cannot run. Live: the repository secrets may not
 * exist yet, so the flow is skipped through `skip` (loudly). CI and local runs must never pass a gate by skipping.
 */
export function requireAdmin(skip: (condition: boolean, description: string) => void): { phone: string; password: string } {
  if (admin.phone && admin.password) return admin;
  skip(live, "E2E_ADMIN_PHONE / E2E_ADMIN_PASSWORD are not set: the live flow needs the E2E Test Branch admin (repository secrets).");
  throw new Error(
    "E2E_ADMIN_PHONE and E2E_ADMIN_PASSWORD must be set: create the account with " +
      '`php artisan bakery:create-admin <phone> --branch="E2E Test Branch" --password=<password>`.',
  );
}
