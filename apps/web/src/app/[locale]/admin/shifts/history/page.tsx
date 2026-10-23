import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { HistoryScreen } from "@/components/shifts/admin/HistoryScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_history");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_history")} intro={t("intro_history")} />
      <HistoryScreen />
    </RequireAuth>
  );
}
