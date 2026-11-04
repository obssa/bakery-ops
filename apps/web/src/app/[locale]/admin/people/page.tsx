import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";
import { PeopleScreen } from "./PeopleScreen";

export const generateMetadata = titledBy("people", "title");

export default async function PeoplePage(props: LocaleProps) {
  const locale = await enterLocale(props);
  return <PeopleScreen titleAm={await amharicTwin(locale, "people", "title")} />;
}
