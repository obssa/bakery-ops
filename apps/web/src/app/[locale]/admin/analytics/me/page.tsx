import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { MeScreen } from "@/components/analytics/PeopleScreens";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("analytics", "me.title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the range: query parameters, read on the client (static export)
  return (
    <RequireAuth permission="analytics.view_own">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <MeScreen />
      </Suspense>
    </RequireAuth>
  );
}
