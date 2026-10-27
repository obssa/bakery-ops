import { RequireAuth } from "@/components/RequireAuth";
import { ChannelsScreen } from "@/components/payments/ChannelsScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminPayments", "channels.title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="channels.manage">
      <ChannelsScreen />
    </RequireAuth>
  );
}
