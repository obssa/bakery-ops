import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { ReasonsScreen } from "@/components/shifts/admin/ReasonsScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminShifts", "title_reasons");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("adminShifts");
  const titleAm = await amharicTwin(locale, "adminShifts", "title_reasons");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_reasons")} intro={t("intro_reasons")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <ReasonsScreen />
      </div>
    </RequireAuth>
  );
}
