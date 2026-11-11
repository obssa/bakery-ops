import { Suspense } from "react";
import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { TemplatesScreen } from "@/components/shifts/admin/TemplatesScreen";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminShifts", "title_templates");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("adminShifts");
  const titleAm = await amharicTwin(locale, "adminShifts", "title_templates");
  // the screen reads ?station= (the setup check's link) on the client; a static page needs it behind Suspense
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("title_templates")} intro={t("intro_templates")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        help={{ label: t("page_help"), text: t("help_templates") }}
      />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <Suspense fallback={null}>
          <TemplatesScreen />
        </Suspense>
      </div>
    </RequireAuth>
  );
}
