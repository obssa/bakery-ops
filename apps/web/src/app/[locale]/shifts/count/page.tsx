import { RequireAuth } from "@/components/RequireAuth";
import { CountPlaceholder } from "@/components/shifts/CountPlaceholder";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("shifts", "count_title");

// The count screen of step 3.7b lands here (?shift=<id>): lines with expected, carried and counted quantities, the reasons, the review.
// Until then the shift bar links to this page so that the link and the route exist, and the page says what to do instead.
export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="shifts.view">
      <CountPlaceholder />
    </RequireAuth>
  );
}
