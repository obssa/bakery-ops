import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { ShiftsOverview } from "@/components/shifts/ApprovalQueue";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("counts", "admin_title");

// The admin shifts overview: the setup check, the shifts running now and the approval queue of step 3.7b. The other sections (templates,
// roster, history, notes, replay, reasons) are the pages beside it (step 3.7c). Step 6.4d: the title row with its Amharic twin, the
// section's tabs under it, then the shifts running now beside the setup check from 1280 px, and the counts waiting for a decision. The
// explain-itself follow-up puts "How shifts work" above them (ShiftsOverview).
export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const t = await getTranslations("counts");
  const titleAm = await amharicTwin(locale, "counts", "admin_title");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("admin_title")} intro={t("admin_intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />
        <ShiftsOverview />
      </div>
    </RequireAuth>
  );
}
