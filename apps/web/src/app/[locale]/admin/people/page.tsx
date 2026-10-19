import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { PeopleScreen } from "./PeopleScreen";

export const generateMetadata = titledBy("people", "title");

export default async function PeoplePage(props: LocaleProps) {
  await enterLocale(props);
  return <PeopleScreen />;
}
