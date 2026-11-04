import { Suspense } from "react";
import { ProductionBoard } from "@/components/production/ProductionBoard";
import { productionTwins } from "@/components/production/twins";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("production", "title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  // the Amharic names beside the English ones on the English page (fresh-counter.md 3.5), read from am.json at build time
  const twins = await productionTwins(locale);
  // the board reads ?request= (the link of a notification) on the client; a static page needs it behind Suspense
  return (
    <RequireAuth permission="production.view">
      <Suspense fallback={null}>
        <ProductionBoard twins={twins} />
      </Suspense>
    </RequireAuth>
  );
}
