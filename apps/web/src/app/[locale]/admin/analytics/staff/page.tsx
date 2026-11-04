import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { AnalyticsGate } from "@/components/analytics/shared";
import { StaffScreen } from "@/components/analytics/PeopleScreens";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("analytics", "staff.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = await amharicTwin(locale, "analytics", "staff.title");
  // the range and ?role_kind=: query parameters, read on the client (static export)
  return (
    <AnalyticsGate>
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <StaffScreen titleAm={titleAm} />
      </Suspense>
    </AnalyticsGate>
  );
}
