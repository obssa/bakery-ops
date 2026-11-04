import { PlannerScreen } from "@/components/admin/PlannerScreen";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("planner", "title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "planner", "title");
  return (
    <RequireAuth permission="production.plan">
      <PlannerScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
