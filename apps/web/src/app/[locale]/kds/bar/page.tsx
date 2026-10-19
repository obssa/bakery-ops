import { ComingSoon } from "@/components/ComingSoon";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("kds", "bar_title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return <ComingSoon namespace="kds" titleKey="bar_title" />;
}
