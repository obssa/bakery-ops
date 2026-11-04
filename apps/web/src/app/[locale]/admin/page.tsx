import { getTranslations } from "next-intl/server";
import { TodayScreen } from "@/components/today/TodayScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("today", "title");

// The admin console's first page is Today (step 6.4a, fresh-counter.md 4.2): the sections it used to list as cards are the sidebar's.
export default async function AdminHome(props: LocaleProps) {
  const locale = await enterLocale(props);
  // the English page sets the Amharic title beside its own, and the Shifts card's, as the Admin board does (spec 5); the Amharic page
  // keeps its titles alone
  const am = locale === "en" ? await getTranslations({ locale: "am", namespace: "today" }) : null;
  return <TodayScreen titleAm={am ? am("title") : undefined} shiftsAm={am ? am("shifts_title") : undefined} />;
}
