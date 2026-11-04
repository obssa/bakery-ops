import { RequireAuth } from "@/components/RequireAuth";
import { LedgerScreen } from "@/components/inventory/LedgerScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("inventory", "nav.ledger");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "inventory", "ledger.title");
  return (
    <RequireAuth permission="inventory.view">
      <LedgerScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
