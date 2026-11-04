import { RequireAuth } from "@/components/RequireAuth";
import { ReceiptSettingsScreen } from "@/components/payments/ReceiptSettingsScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminPayments", "receipts.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "adminPayments", "receipts.title");
  return (
    <RequireAuth permission="payments.take">
      <ReceiptSettingsScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
