import { RequireAuth } from "@/components/RequireAuth";
import { SuppliersScreen } from "@/components/inventory/SuppliersScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("inventory", "nav.suppliers");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "inventory", "suppliers.title");
  return (
    <RequireAuth permission="purchases.record">
      <SuppliersScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
