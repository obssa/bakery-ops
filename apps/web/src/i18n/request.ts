import { hasLocale } from "next-intl";
import { getRequestConfig } from "next-intl/server";
import { routing } from "./routing";

// Static rendering: the locale comes from the [locale] segment (setRequestLocale in every layout and page), never
// from cookies or headers, so every page can be exported. Pages outside [locale] (the start page, the bare 404)
// render with the default locale.
export default getRequestConfig(async ({ requestLocale }) => {
  const requested = await requestLocale;
  const locale = hasLocale(routing.locales, requested) ? requested : routing.defaultLocale;
  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
