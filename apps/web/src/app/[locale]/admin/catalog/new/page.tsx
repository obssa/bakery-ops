import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { NewItemPage } from "@/components/catalog/NewItemPage";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("catalog", "new_item");

// Admin > Catalog > New product or New ingredient (phase-6/new-item-page): catalog.manage, the admin; anyone else is told the page is not
// theirs (the API answers 403 too).
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the kind (?kind=product|ingredient) is a query parameter, read on the client (static export): useSearchParams needs a boundary
  return (
    <RequireAuth permission="catalog.manage">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <NewItemPage />
      </Suspense>
    </RequireAuth>
  );
}
