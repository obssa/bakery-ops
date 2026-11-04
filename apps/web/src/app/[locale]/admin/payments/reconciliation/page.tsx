import { RequireAuth } from "@/components/RequireAuth";
import { ReconciliationScreen } from "@/components/payments/ReconciliationScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminPayments", "reconciliation.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "adminPayments", "reconciliation.title");
  return (
    <RequireAuth permission="payments.verify">
      <ReconciliationScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
