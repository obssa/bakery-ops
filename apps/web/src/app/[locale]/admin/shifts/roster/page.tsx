import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { RosterScreen } from "@/components/shifts/admin/RosterScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_roster");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_roster")} intro={t("intro_roster")} />
      <RosterScreen />
    </RequireAuth>
  );
}
