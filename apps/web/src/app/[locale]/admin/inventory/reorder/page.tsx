import { RequireAuth } from "@/components/RequireAuth";
import { ReorderScreen } from "@/components/inventory/ReorderScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("inventory", "nav.reorder");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="inventory.view">
      <ReorderScreen />
    </RequireAuth>
  );
}
