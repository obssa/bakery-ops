import { isLocale, routing, type Locale } from "@/i18n/routing";
import { request } from "./api/client";
import type { Me } from "./api/types";
import { storedToken } from "./session";

const BASE = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

/** The language for /app/: the signed-in user's own (GET /me), else the browser's, else English. */
export async function chooseLocale(): Promise<Locale> {
  const token = storedToken();
  if (token) {
    try {
      const me = await request<Me>("/me", { token, signal: AbortSignal.timeout(5000) });
      if (isLocale(me.locale)) return me.locale;
    } catch {
      // offline or signed out: fall back to the browser
    }
  }
  const languages = navigator.languages?.length ? navigator.languages : [navigator.language];
  for (const tag of languages) {
    const primary = tag.toLowerCase().split("-")[0];
    if (isLocale(primary)) return primary;
  }
  return routing.defaultLocale;
}

/**
 * Where the start page sends this URL: /app/admin/catalog/?kind=product -> /app/<locale>/admin/catalog/?kind=product
 * (LiteSpeed serves the start page for any /app path without a language). A path that already has a language but
 * does not exist goes to that language's home, so the start page can never send a visitor back to itself.
 */
export function startTarget(pathname: string, search: string, hash: string, locale: Locale): string {
  let rest = BASE && pathname.startsWith(BASE) ? pathname.slice(BASE.length) : pathname;
  if (!rest.startsWith("/")) rest = `/${rest}`;
  const first = rest.split("/").filter(Boolean)[0];
  if (isLocale(first)) return `${BASE}/${first}/`;
  if (!rest.endsWith("/")) rest = `${rest}/`;
  return `${BASE}/${locale}${rest}${search}${hash}`;
}
