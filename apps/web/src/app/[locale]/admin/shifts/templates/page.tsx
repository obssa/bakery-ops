import { Suspense } from "react";
import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { TemplatesScreen } from "@/components/shifts/admin/TemplatesScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminShifts", "title_templates");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("adminShifts");
  // the screen reads ?station= (the setup check's link) on the client; a static page needs it behind Suspense
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_templates")} intro={t("intro_templates")} />
      <Suspense fallback={null}>
        <TemplatesScreen />
      </Suspense>
    </RequireAuth>
  );
}
