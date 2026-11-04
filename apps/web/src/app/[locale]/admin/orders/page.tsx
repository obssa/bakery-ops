import { OrdersBoard } from "@/components/admin/OrdersBoard";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { amharicTwin } from "@/lib/twin";

export const generateMetadata = titledBy("adminOrders", "title");

export default async function Page(props: LocaleProps) {
  const locale = await enterLocale(props);
  // the title row sets the Amharic title beside the English one (step 6.4c, fresh-counter.md 4.1)
  const titleAm = await amharicTwin(locale, "adminOrders", "title");
  return (
    <RequireAuth permission="orders.create">
      <OrdersBoard titleAm={titleAm} />
    </RequireAuth>
  );
}
