import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { ReplayScreen } from "@/components/shifts/admin/ReplayScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_replay");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  return (
    <RequireAuth permission="shifts.replay">
      <PageHeader title={t("title_replay")} intro={t("intro_replay")} />
      <ReplayScreen />
    </RequireAuth>
  );
}
