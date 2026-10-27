import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { PaymentsScreen } from "@/components/payments/PaymentsScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminPayments", "list.title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the filters and the payment a notification links to (?payment=) are query parameters, read on the client (static export)
  return (
    <RequireAuth permission="payments.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <PaymentsScreen />
      </Suspense>
    </RequireAuth>
  );
}
