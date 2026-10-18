import { getRequestConfig } from "next-intl/server";
import { cookies } from "next/headers";

export const locales = ["en", "am"] as const;
export type Locale = (typeof locales)[number];
export const defaultLocale: Locale = "en";

// Locale comes from a cookie (per device: the kitchen tablet runs in Amharic, the admin console in
// English). Phase 0.7 adds the per-user locale from GET /me and a switcher in the shell.
export default getRequestConfig(async () => {
  const store = await cookies();
  const raw = store.get("locale")?.value;
  const locale: Locale = (locales as readonly string[]).includes(raw ?? "") ? (raw as Locale) : defaultLocale;
  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
