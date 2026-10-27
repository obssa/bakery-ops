import type { ReactNode } from "react";
import { Suspense } from "react";
import { AnalyticsNav } from "@/components/analytics/shared";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Analytics (step 4.5e): the overview, sales, payment methods, products (with the product page), stations, staff and shifts for
// analytics.view, and the person's own figures for analytics.view_own, each a page under the section's pills, which keep the range of the
// page's address. The pills read the address on the client (static export), so they wait in a Suspense boundary.
export default async function AnalyticsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return (
    <>
      <Suspense fallback={<div className="mb-6 min-h-12" />}>
        <AnalyticsNav />
      </Suspense>
      {children}
    </>
  );
}
