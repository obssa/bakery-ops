import { getTranslations } from "next-intl/server";

// The Amharic title an admin page sets beside its English one (fresh-counter.md 4.1, the boards' "Catalog · ካታሎግ"): read on the server
// from am.json while the English page is prerendered (the static export has both locales), and handed to the screen as `titleAm`
// (PageHeader's `twin`). The Amharic page keeps its title alone, so this gives nothing there.

/** The entry `key` of `namespace` in Amharic, for the English page only. `values` fill its placeholders (a "{no}" may be left as is). */
export async function amharicTwin(locale: string, namespace: string, key: string, values?: Record<string, string>): Promise<string | undefined> {
  if (locale !== "en") return undefined;
  const t = await getTranslations({ locale: "am", namespace });
  return t(key, values);
}
