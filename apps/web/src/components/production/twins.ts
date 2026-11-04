import { getTranslations } from "next-intl/server";
import type { Column } from "@/lib/live/production";

// The Amharic names the production board sets beside or under the English ones on the English page (fresh-counter.md 3.5, the Production
// board: "Production ምርት", each column's name over its Amharic name): read from am.json on the server when the page is prerendered, as
// the catalog list's title twin is (step 6.2b). The Amharic page shows its names alone.

export interface ProductionTwins {
  title: string;
  columns: Record<Column, string>;
}

export async function productionTwins(locale: string): Promise<ProductionTwins | undefined> {
  if (locale !== "en") return undefined;
  const am = await getTranslations({ locale: "am", namespace: "production" });
  return {
    title: am("title"),
    columns: {
      requested: am("col_requested"),
      in_progress: am("col_in_progress"),
      pending_confirmation: am("col_pending_confirmation"),
      done_today: am("col_done_today"),
    },
  };
}
