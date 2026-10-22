import { SCREENS } from "./screens";

// Where an inbox line can take the person. The API names a path ("/production?request=12", "/inventory?item=3"); a path
// the web has no page for yet is shown without a link rather than as one that ends on "not found".

const PAGES = new Set<string>([
  ...SCREENS.map((screen) => screen.href),
  "/admin/catalog",
  "/admin/orders",
  "/admin/people",
  "/admin/inventory",
  "/admin/inventory/ledger",
  "/admin/inventory/purchases",
  "/admin/inventory/suppliers",
  "/admin/inventory/transfers",
  "/admin/inventory/reorder",
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
