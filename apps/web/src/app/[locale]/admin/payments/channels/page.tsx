import { RequireAuth } from "@/components/RequireAuth";
import { ChannelsScreen } from "@/components/payments/ChannelsScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminPayments", "channels.title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  const titleAm = await amharicTwin(locale, "adminPayments", "channels.title");
  return (
    <RequireAuth permission="channels.manage">
      <ChannelsScreen titleAm={titleAm} />
    </RequireAuth>
  );
}
