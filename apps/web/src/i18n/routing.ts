import { defineRouting } from "next-intl/routing";

// Two languages, always in the path (/app/en/..., /app/am/...): the static export has one folder per locale and no
// middleware to negotiate one. The start page /app/ picks the locale on the client (src/app/page.tsx).
export const routing = defineRouting({
  locales: ["en", "am"],
  defaultLocale: "en",
  localePrefix: "always",
  localeCookie: false,
});

export type Locale = (typeof routing.locales)[number];

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (routing.locales as readonly string[]).includes(value);
}
