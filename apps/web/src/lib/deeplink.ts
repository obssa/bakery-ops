import { SCREENS } from "./screens";

// Where an inbox line can take the person. The API names a path ("/production?request=12", "/inventory?item=3"); a path
// the web has no page for yet is shown without a link rather than as one that ends on "not found".

const PAGES = new Set<string>([...SCREENS.map((screen) => screen.href), "/admin/catalog", "/admin/orders", "/admin/people"]);

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
  if (!PAGES.has(pathname)) return null;
  return { pathname, query: Object.fromEntries(url.searchParams.entries()) };
}
