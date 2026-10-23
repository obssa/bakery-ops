import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { ApprovalReview } from "@/components/shifts/ApprovalReview";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("counts", "review_page");

// The side-by-side approval of one count (?count=<id>, read on the client: a static export has no request-time params).
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  return (
    <RequireAuth permission="shifts.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <ApprovalReview />
      </Suspense>
    </RequireAuth>
  );
}
