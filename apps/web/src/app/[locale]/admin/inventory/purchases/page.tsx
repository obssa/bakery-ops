import { RequireAuth } from "@/components/RequireAuth";
import { PurchasesScreen } from "@/components/inventory/PurchasesScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("inventory", "nav.purchases");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "inventory", "purchases.title");
  return (
    <RequireAuth permission="purchases.record">
      <PurchasesScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
