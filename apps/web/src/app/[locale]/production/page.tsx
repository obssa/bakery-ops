import { ComingSoon } from "@/components/ComingSoon";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("production", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return <ComingSoon namespace="production" titleKey="title" />;
}
