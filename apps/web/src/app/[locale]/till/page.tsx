import { RequireAuth } from "@/components/RequireAuth";
import { TillScreen } from "@/components/till/TillScreen";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("till", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="payments.take">
      <TillScreen />
    </RequireAuth>
  );
}
