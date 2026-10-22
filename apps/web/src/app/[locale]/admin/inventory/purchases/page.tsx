import { RequireAuth } from "@/components/RequireAuth";
import { PurchasesScreen } from "@/components/inventory/PurchasesScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("inventory", "nav.purchases");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="purchases.record">
      <PurchasesScreen />
    </RequireAuth>
  );
}
