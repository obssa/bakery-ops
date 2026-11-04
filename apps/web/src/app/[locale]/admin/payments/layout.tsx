import type { ReactNode } from "react";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Payments (step 4.5c): the payments list, the reconciliation, the payment methods and the receipt settings, each a page under the
// section's own tabs, shown to the person who may open it. Step 6.4c: each page draws the tabs ("Payment sections") under its own title
// row, as the catalog draws its switch, so the layout only enters the locale.
export default async function PaymentsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return <>{children}</>;
}
