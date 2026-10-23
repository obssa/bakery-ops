import { getTranslations } from "next-intl/server";
import { RequireAuth } from "@/components/RequireAuth";
import { ApprovalQueue } from "@/components/shifts/ApprovalQueue";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("counts", "admin_title");

// The admin shifts section. Step 3.7b mounts the approval queue here; step 3.7c adds the templates, the roster calendar, the running
// shifts, the locked history and the replay around it.
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("counts");
  return (
    <RequireAuth permission="shifts.view">
      <PageHeader title={t("admin_title")} intro={t("admin_intro")} />
      <ApprovalQueue />
    </RequireAuth>
  );
}
