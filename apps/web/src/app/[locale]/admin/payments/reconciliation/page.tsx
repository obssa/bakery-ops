import { RequireAuth } from "@/components/RequireAuth";
import { ReconciliationScreen } from "@/components/payments/ReconciliationScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminPayments", "reconciliation.title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="payments.verify">
      <ReconciliationScreen />
    </RequireAuth>
  );
}
