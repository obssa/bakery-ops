import { RequireAuth } from "@/components/RequireAuth";
import { LedgerScreen } from "@/components/inventory/LedgerScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("inventory", "nav.ledger");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="inventory.view">
      <LedgerScreen />
    </RequireAuth>
  );
}
