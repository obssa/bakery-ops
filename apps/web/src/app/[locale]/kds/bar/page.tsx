import { KdsScreen } from "@/components/kds/KdsScreen";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("kds", "bar_title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="orders.accept">
      <KdsScreen kind="bar" />
    </RequireAuth>
  );
}
