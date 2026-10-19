import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { CatalogScreen } from "./CatalogScreen";

export const generateMetadata = titledBy("catalog", "title");

export default async function CatalogPage(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the kind filter is the ?kind= query, read on the client (static export): useSearchParams needs a boundary
  return (
    <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
      <CatalogScreen />
    </Suspense>
  );
}
