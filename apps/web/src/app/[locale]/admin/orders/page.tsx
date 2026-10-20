import { OrdersBoard } from "@/components/admin/OrdersBoard";
import { RequireAuth } from "@/components/RequireAuth";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("adminOrders", "title");

export default async function Page(props: LocaleProps) {
  await enterLocale(props);
  return (
    <RequireAuth permission="orders.create">
      <OrdersBoard />
    </RequireAuth>
  );
}
