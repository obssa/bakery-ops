import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";

// Every page under [locale] is prerendered once per locale (static export): it reads the locale from the segment and
// hands it to next-intl (setRequestLocale) instead of letting it look at cookies or headers.

export interface LocaleProps {
  params: Promise<{ locale: string }>;
}

export async function enterLocale({ params }: LocaleProps): Promise<string> {
  const { locale } = await params;
  setRequestLocale(locale);
  return locale;
}

/** generateMetadata for a page titled by one dictionary entry ("<title> · Bakery Ops" via the layout template). */
export function titledBy(namespace: string, key: string) {
  return async ({ params }: LocaleProps): Promise<Metadata> => {
    const { locale } = await params;
    const t = await getTranslations({ locale, namespace });
    return { title: t(key) };
  };
}
