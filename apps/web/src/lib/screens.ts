// The screens of the system, in the order the shell lists them. All of them are live as of step 2.8a (the production board was
// the last one that said "available in Phase 2").
export const SCREENS = [
  { href: "/pad", key: "pad" },
  { href: "/till", key: "till" },
  { href: "/kds/kitchen", key: "kds_kitchen" },
  { href: "/kds/bar", key: "kds_bar" },
  { href: "/production", key: "production" },
  { href: "/admin", key: "admin" },
] as const;

export type ScreenNavKey = (typeof SCREENS)[number]["key"];

/** Whether `href` is the page at `pathname` or one of its pages ("/admin" is current on /admin/catalog/). */
export function isCurrent(pathname: string, href: string): boolean {
  const path = pathname.replace(/\/+$/, "") || "/";
  return path === href || path.startsWith(`${href}/`);
}

/**
 * The frame the shell draws around a page (step 6.2b, fresh-counter.md 3, 3.1 and 4.1). `admin`: the admin console, under the station top
 * bar, draws its own sidebar frame and its `main` (AdminFrame). `login`: the sign-in screen is a full-bleed split of its own, without the
 * top bar. `fill` (step 6.3a): a station screen that draws its own zones edge to edge under the top bar, the waiter pad's menu and order
 * panel (fresh-counter.md 3.2); from 1024 px it holds the rest of the window, and each zone scrolls on its own. `station`: every other page
 * sits under the station top bar in the shell's padded `main`.
 */
export type ShellFrame = "admin" | "login" | "fill" | "station";

/** The station screens drawn edge to edge (`fill`). */
const FILL_SCREENS = ["/pad"] as const;

export function frameOf(pathname: string): ShellFrame {
  if (isCurrent(pathname, "/admin")) return "admin";
  if (isCurrent(pathname, "/login")) return "login";
  if (FILL_SCREENS.some((href) => isCurrent(pathname, href))) return "fill";
  return "station";
}
