import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { ReasonsScreen } from "@/components/shifts/admin/ReasonsScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_reasons");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_reasons")} intro={t("intro_reasons")} />
      <ReasonsScreen />
    </RequireAuth>
  );
}
