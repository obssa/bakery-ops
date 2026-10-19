import { ComingSoon } from "@/components/ComingSoon";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("till", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return <ComingSoon namespace="till" titleKey="title" />;
}
