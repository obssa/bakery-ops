import { getTranslations } from "next-intl/server";

// The Amharic names the kitchen and bar displays set beside the English ones on the English page (fresh-counter.md 3.3, the Kitchen board:
// "Kitchen ወጥ ቤት", "New አዲስ"): read from am.json on the server when the page is prerendered, as the catalog list's title twin is
// (step 6.2b). The Amharic page shows its names alone.

export interface KdsTwins {
  title: string;
  new: string;
  cooking: string;
  ready: string;
}

export async function kdsTwins(locale: string, kind: "kitchen" | "bar"): Promise<KdsTwins | undefined> {
  if (locale !== "en") return undefined;
  const am = await getTranslations({ locale: "am", namespace: "kds" });
  return {
    title: kind === "kitchen" ? am("kitchen_title") : am("bar_title"),
    new: am("column_new"),
    cooking: kind === "kitchen" ? am("column_cooking") : am("column_making"),
    ready: am("column_ready"),
  };
}
