import { RequireAuth } from "@/components/RequireAuth";
import { ReceiptSettingsScreen } from "@/components/payments/ReceiptSettingsScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminPayments", "receipts.title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="payments.take">
      <ReceiptSettingsScreen />
    </RequireAuth>
  );
}
