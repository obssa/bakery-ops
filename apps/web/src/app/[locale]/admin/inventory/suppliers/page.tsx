import { RequireAuth } from "@/components/RequireAuth";
import { SuppliersScreen } from "@/components/inventory/SuppliersScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("inventory", "nav.suppliers");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="purchases.record">
      <SuppliersScreen />
    </RequireAuth>
  );
}
