import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { CatalogScreen } from "./CatalogScreen";

export const generateMetadata = titledBy("catalog", "title");

export default async function CatalogPage(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("common");
  // the list names every item in both languages, so its title row sets the Amharic title beside the English one (step 6.2b,
  // fresh-counter.md 4.1 and 5); the Amharic page keeps its title alone
  const titleAm = locale === "en" ? (await getTranslations({ locale: "am", namespace: "catalog" }))("title") : undefined;
  // the kind filter is the ?kind= query, read on the client (static export): useSearchParams needs a boundary
  return (
    <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
      <CatalogScreen titleAm={titleAm} />
    </Suspense>
  );
}
