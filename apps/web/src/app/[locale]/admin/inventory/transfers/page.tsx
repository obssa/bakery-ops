import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { RequireAuth } from "@/components/RequireAuth";
import { TransfersScreen } from "@/components/inventory/TransfersScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("inventory", "nav.transfers");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("common");
  // the filters a notification link carries are query parameters, read on the client (static export)
  return (
    <RequireAuth permission="inventory.view">
      <Suspense fallback={<p className="text-base text-neutral-600">{t("loading")}</p>}>
        <TransfersScreen />
      </Suspense>
    </RequireAuth>
  );
}
