import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { ReplayScreen } from "@/components/shifts/admin/ReplayScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminShifts", "title_replay");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("adminShifts");
  const titleAm = await amharicTwin(locale, "adminShifts", "title_replay");
  return (
    <RequireAuth permission="shifts.replay">
      <PageHeader title={t("title_replay")} intro={t("intro_replay")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <ReplayScreen />
      </div>
    </RequireAuth>
  );
}
