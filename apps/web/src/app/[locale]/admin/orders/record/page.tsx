import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { OrderRecordScreen } from "@/components/admin/OrderRecord";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("orderRecord", "page_title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  // the Amharic title beside the English one (step 6.4c), with "{no}" left for the order's number, which only the client knows
  const titleAm = await amharicTwin(locale, "orderRecord", "title", { no: "{no}" });
  // the order (?id=, a record page: the static export has no dynamic segment) is a query parameter, read on the client. The permission is
  // lib/orderRecord.ts RECORD_PERMISSION (payments.verify: the admin and the manager), written out here because this is a server module.
  return (
    <RequireAuth permission="payments.verify">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <OrderRecordScreen titleAm={titleAm} />
      </Suspense>
    </RequireAuth>
  );
}
