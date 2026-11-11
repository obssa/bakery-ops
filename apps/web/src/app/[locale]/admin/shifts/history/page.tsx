import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { HistoryScreen } from "@/components/shifts/admin/HistoryScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminShifts", "title_history");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("adminShifts");
  const titleAm = await amharicTwin(locale, "adminShifts", "title_history");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_history")} intro={t("intro_history")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        help={{ label: t("page_help"), text: t("help_history") }}
      />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <HistoryScreen />
      </div>
    </RequireAuth>
  );
}
