import type { ReactNode } from "react";
import { InventoryNav } from "@/components/inventory/shared";
import { enterLocale, type LocaleProps } from "@/lib/page";

export default async function InventoryLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return (
    <>
      <InventoryNav />
      {children}
    </>
  );
}
