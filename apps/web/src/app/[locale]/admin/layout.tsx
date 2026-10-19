import type { ReactNode } from "react";
import { AdminFrame } from "@/components/AdminFrame";
import { enterLocale, type LocaleProps } from "@/lib/page";

export default async function AdminLayout({ children, ...props }: LocaleProps & { children: ReactNode }) {
  await enterLocale(props);
  return <AdminFrame>{children}</AdminFrame>;
}
