import { RequireAuth } from "@/components/RequireAuth";
import { SystemScreen } from "@/components/system/SystemScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("system", "title");

// Admin > System (step 5.5c, phase-5 plan 4.4): system.view (the admin); anyone else is told the page is not theirs (the API answers 403 too).
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="system.view">
      <SystemScreen />
    </RequireAuth>
  );
}
