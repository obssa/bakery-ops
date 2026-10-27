import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { OrderRecordScreen } from "@/components/admin/OrderRecord";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("orderRecord", "page_title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the order (?id=, a record page: the static export has no dynamic segment) is a query parameter, read on the client. The permission is
  // lib/orderRecord.ts RECORD_PERMISSION (payments.verify: the admin and the manager), written out here because this is a server module.
  return (
    <RequireAuth permission="payments.verify">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <OrderRecordScreen />
      </Suspense>
    </RequireAuth>
  );
}
