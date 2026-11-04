import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { AnalyticsGate } from "@/components/analytics/shared";
import { SalesScreen } from "@/components/analytics/SalesScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("analytics", "sales.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = await amharicTwin(locale, "analytics", "sales.title");
  // the range and ?group=day|week|month: query parameters, read on the client (static export)
  return (
    <AnalyticsGate>
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <SalesScreen titleAm={titleAm} />
      </Suspense>
    </AnalyticsGate>
  );
}
