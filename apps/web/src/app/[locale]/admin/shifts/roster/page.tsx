import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { RosterScreen } from "@/components/shifts/admin/RosterScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminShifts", "title_roster");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("adminShifts");
  const titleAm = await amharicTwin(locale, "adminShifts", "title_roster");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_roster")} intro={t("intro_roster")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        help={{ label: t("page_help"), text: t("help_roster") }}
      />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <RosterScreen />
      </div>
    </RequireAuth>
  );
}
