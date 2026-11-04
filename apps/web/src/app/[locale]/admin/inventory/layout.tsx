import type { ReactNode } from "react";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Inventory: stock, the ledger, purchases, suppliers, transfers and reorder, each a page under the section's own tabs. Step 6.4c:
// each page draws the tabs ("Inventory sections") under its own title row, as the catalog draws its switch, so the layout only enters the
// locale.
export default async function InventoryLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return <>{children}</>;
}
