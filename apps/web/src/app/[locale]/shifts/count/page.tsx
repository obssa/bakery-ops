import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { CountScreen } from "@/components/shifts/CountScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("counts", "title");

// The count screen of step 3.7b (?shift=<id>, read on the client: a static export has no request-time params). One component serves the
// opening and the closing count; the shift bar of every station screen links here. On the English page the screen's Amharic name sits
// beside its own (step 6.3c, as the station screens show it), read from am.json at build time.
export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = locale === "en" ? (await getTranslations({ locale: "am", namespace: "counts" }))("title") : undefined;
  return (
    <RequireAuth permission="shifts.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <CountScreen titleAm={titleAm} />
      </Suspense>
    </RequireAuth>
  );
}
