import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { ApprovalQueue } from "@/components/shifts/ApprovalQueue";
import { RunningShifts, SetupCheck } from "@/components/shifts/admin/Overview";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("counts", "admin_title");

// The admin shifts overview: the setup check, the shifts running now and the approval queue of step 3.7b. The other sections (templates,
// roster, history, notes, replay, reasons) are the pages beside it (step 3.7c).
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("counts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("admin_title")} intro={t("admin_intro")} />
      <div className="space-y-6">
        <SetupCheck />
        <RunningShifts />
        <ApprovalQueue />
      </div>
    </RequireAuth>
  );
}
