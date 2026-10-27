import type { ReactNode } from "react";
import { PaymentsNav } from "@/components/payments/shared";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Payments (step 4.5c): the payments list, the reconciliation, the payment methods and the receipt settings, each a page under the
// section's own tabs, shown to the person who may open it.
export default async function PaymentsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return (
    <>
      <PaymentsNav />
      {children}
    </>
  );
}
