import { RequireAuth } from "@/components/RequireAuth";
import { TablesScreen } from "@/components/tables/TablesScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

// Admin > Tables (Phase 6 follow-up): the dining tables the pads show, for tables.manage (the admin and the manager).
export const generateMetadata = titledBy("tables", "title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "tables", "title");
  return (
    <RequireAuth permission="tables.manage">
      <TablesScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
