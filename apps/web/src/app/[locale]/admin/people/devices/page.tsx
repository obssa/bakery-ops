import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { DevicesScreen } from "./DevicesScreen";

export const generateMetadata = titledBy("devices", "title");

export default async function DevicesPage(props: LocaleProps) {
  await enterLocale(props);
  return <DevicesScreen />;
}
