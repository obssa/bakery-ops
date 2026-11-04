import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { TillScreen } from "@/components/till/TillScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("till", "title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  // the Till board sets the Amharic name beside the English one (fresh-counter.md 3.4), read from am.json on the server as the catalog
  // list's title twin is; the Amharic page keeps its title alone
  const titleTwin = locale === "en" ? (await getTranslations({ locale: "am", namespace: "till" }))("title") : undefined;
  return (
    <RequireAuth permission="payments.take">
      <TillScreen titleTwin={titleTwin} />
    </RequireAuth>
  );
}
