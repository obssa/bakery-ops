import { RequireAuth } from "@/components/RequireAuth";
import { CountingRulesScreen } from "@/components/system/CountingRulesScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("counting_rules", "title");

// Admin > System > Counting rules (ADR 0026): shifts.manage (the admin); anyone else is told the page is not theirs (the API answers 403 too).
export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "counting_rules", "title");
  return (
    <RequireAuth permission="shifts.manage">
      <CountingRulesScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
