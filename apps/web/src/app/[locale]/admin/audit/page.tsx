import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { AuditScreen } from "@/components/audit/AuditScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("audit", "title");

// Admin > Audit log (step 4.5f): audit.view (admin and manager); anyone else is told the page is not theirs (the API answers 403 too).
export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = await amharicTwin(locale, "audit", "title");
  // the filters and the entry (?id=) are query parameters, read on the client (static export)
  return (
    <RequireAuth permission="audit.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <AuditScreen titleAm={titleAm} />
      </Suspense>
    </RequireAuth>
  );
}
