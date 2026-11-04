import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";
import { DevicesScreen } from "./DevicesScreen";

export const generateMetadata = titledBy("devices", "title");

export default async function DevicesPage(props: LocaleProps) {
  const locale = await enterLocale(props);
  return <DevicesScreen titleAm={await amharicTwin(locale, "devices", "title")} />;
}
