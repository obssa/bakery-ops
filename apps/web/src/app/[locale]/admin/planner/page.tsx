import { PlannerScreen } from "@/components/admin/PlannerScreen";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("planner", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="production.plan">
      <PlannerScreen />
    </RequireAuth>
  );
}
