import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { NotesScreen } from "@/components/shifts/admin/NotesScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_notes");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_notes")} intro={t("intro_notes")} />
      <NotesScreen />
    </RequireAuth>
  );
}
