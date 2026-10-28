import { SCREENS } from "./screens";

// Where an inbox line can take the person. The API names a path ("/production?request=12", "/inventory?item=3"); a path
// the web has no page for yet is shown without a link rather than as one that ends on "not found".

const PAGES = new Set<string>([
  ...SCREENS.map((screen) => screen.href),
  "/admin/catalog",
  "/admin/orders",
  // step 4.5d: the order record (?id=); step 4.5g: the order_refunded line (NotifyRefund, step 4.3d) links here too
  "/admin/orders/record",
  "/admin/people",
  "/admin/inventory",
  "/admin/inventory/ledger",
  "/admin/inventory/purchases",
  "/admin/inventory/suppliers",
  "/admin/inventory/transfers",
  "/admin/inventory/reorder",
  // step 4.5c: the payment_disputed line links to /admin/payments?payment={id} (step 4.3c)
  "/admin/payments",
  "/admin/payments/reconciliation",
  "/admin/payments/channels",
  "/admin/payments/receipts",
  // step 4.5e: the weekly_summary line (step 4.4d) links to /admin/analytics?from={week_start}&to={week_end}, the week's range
  "/admin/analytics",
  "/admin/analytics/sales",
  "/admin/analytics/channels",
  "/admin/analytics/products",
  "/admin/analytics/product",
  "/admin/analytics/stations",
  "/admin/analytics/staff",
  "/admin/analytics/shifts",
  "/admin/analytics/me",
  // step 4.5f: the audit log (?id= opens a row; the filters ride along)
  "/admin/audit",
]);

// The API's notification registry names the stock paths it had before the admin Inventory pages existed (stock alerts:
// /inventory?item=&location=, transfers: /inventory/transfers?transfer=). They are mapped here, in the web, to the pages
// that now exist; the API's links (and their tests) stay as they were.
const ALIASES: Record<string, string> = {
  "/inventory": "/admin/inventory",
  "/inventory/transfers": "/admin/inventory/transfers",
};

export interface ResolvedLink {
  pathname: string;
  query: Record<string, string>;
}

export function resolveDeepLink(link: string | null): ResolvedLink | null {
  if (!link || !link.startsWith("/")) return null;
  let url: URL;
  try {
    url = new URL(link, "http://deep.link");
  } catch {
    return null;
  }
  const pathname = url.pathname.replace(/\/+$/, "") || "/";
  const target = ALIASES[pathname] ?? pathname;
  if (!PAGES.has(target)) return null;
  return { pathname: target, query: Object.fromEntries(url.searchParams.entries()) };
}
