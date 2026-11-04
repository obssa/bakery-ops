import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { ApprovalReview } from "@/components/shifts/ApprovalReview";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("counts", "review_page");

// The side-by-side approval of one count (?count=<id>, read on the client: a static export has no request-time params). The Amharic
// twin of its title keeps "{id}" for the client to fill.
export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  const titleAm = await amharicTwin(locale, "counts", "count_number", { id: "{id}" });
  return (
    <RequireAuth permission="shifts.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <ApprovalReview titleAm={titleAm} />
      </Suspense>
    </RequireAuth>
  );
}
