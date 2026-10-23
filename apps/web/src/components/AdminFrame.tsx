"use client";

import { useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { Link, usePathname } from "@/i18n/navigation";
import { RequireAuth } from "./RequireAuth";
import { focusRing } from "./styles";

// The admin console's own frame: signed-in only, with its sections as tabs: Catalog and People (Phase 0), the live
// Orders board (Phase 1), Inventory with its own sub-tabs (Phase 2). The header's "Admin console" link is the overview.
const SECTIONS = [
  { href: "/admin/catalog", key: "catalog" },
  { href: "/admin/people", key: "people" },
  { href: "/admin/orders", key: "orders" },
  { href: "/admin/inventory", key: "inventory" },
  { href: "/admin/planner", key: "planner" },
  { href: "/admin/shifts", key: "shifts" },
] as const;

export function AdminFrame({ children }: { children: ReactNode }) {
  const t = useTranslations("admin");
  const pathname = usePathname().replace(/\/+$/, "");

  return (
    <RequireAuth>
      <nav aria-label={t("sections")} className="mb-6 flex gap-1 border-b border-neutral-200">
        {SECTIONS.map(({ href, key }) => {
          const current = pathname === href || pathname.startsWith(`${href}/`);
          return (
            <Link
              key={href}
              href={href}
              aria-current={current ? "page" : undefined}
              className={`-mb-px inline-flex min-h-12 items-center border-b-2 px-4 text-base ${
                current ? "border-blue-700 font-medium text-neutral-900" : "border-transparent text-neutral-600 hover:text-neutral-900"
              } ${focusRing}`}
            >
              {t(key)}
            </Link>
          );
        })}
      </nav>
      {children}
    </RequireAuth>
  );
}
