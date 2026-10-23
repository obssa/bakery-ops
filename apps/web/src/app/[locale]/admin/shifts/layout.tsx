import type { ReactNode } from "react";
import { AdminShiftsNav } from "@/components/shifts/admin/common";
import { enterLocale, type LocaleProps } from "@/lib/page";

export default async function ShiftsLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return (
    <>
      <AdminShiftsNav />
      {children}
    </>
  );
}
