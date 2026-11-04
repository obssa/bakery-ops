import type { ReactNode } from "react";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Analytics (step 4.5e): the overview, sales, payment methods, products (with the product page), stations, staff and shifts for
// analytics.view, and the person's own figures for analytics.view_own, each a page under the section's tabs, which keep the range of the
// page's address. Step 6.4d: each page draws the tabs ("Analytics sections") under its own title row, inside the Suspense boundary its
// screen already has for the address, so the layout only enters the locale.
export default async function AnalyticsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return <>{children}</>;
}
