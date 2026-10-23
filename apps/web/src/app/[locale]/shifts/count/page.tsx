import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { CountScreen } from "@/components/shifts/CountScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("counts", "title");

// The count screen of step 3.7b (?shift=<id>, read on the client: a static export has no request-time params). One component serves the
// opening and the closing count; the shift bar of every station screen links here.
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  return (
    <RequireAuth permission="shifts.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <CountScreen />
      </Suspense>
    </RequireAuth>
  );
}
