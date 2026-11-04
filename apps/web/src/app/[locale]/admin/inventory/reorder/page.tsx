import { RequireAuth } from "@/components/RequireAuth";
import { ReorderScreen } from "@/components/inventory/ReorderScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("inventory", "nav.reorder");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "inventory", "reorder.title");
  return (
    <RequireAuth permission="inventory.view">
      <ReorderScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
