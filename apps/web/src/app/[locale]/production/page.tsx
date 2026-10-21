import { Suspense } from "react";
import { ProductionBoard } from "@/components/production/ProductionBoard";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("production", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  // the board reads ?request= (the link of a notification) on the client; a static page needs it behind Suspense
  return (
    <RequireAuth permission="production.view">
      <Suspense fallback={null}>
        <ProductionBoard />
      </Suspense>
    </RequireAuth>
  );
}
