import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { MeScreen } from "@/components/analytics/PeopleScreens";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("analytics", "me.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = await amharicTwin(locale, "analytics", "me.title");
  // the range: query parameters, read on the client (static export)
  return (
    <RequireAuth permission="analytics.view_own">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <MeScreen titleAm={titleAm} />
      </Suspense>
    </RequireAuth>
  );
}
