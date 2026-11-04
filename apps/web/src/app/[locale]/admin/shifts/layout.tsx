import type { ReactNode } from "react";
import { enterLocale, type LocaleProps } from "@/lib/page";

// Admin > Shifts (step 3.7c): the overview, templates, roster, history, notes, replay and reasons, each a page under the section's own
// tabs. Step 6.4d: each page draws the tabs ("Shifts sections") under its own title row, as the catalog draws its switch, so the layout
// only enters the locale.
export default async function ShiftsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return <>{children}</>;
}
