"use client";

import { useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { Link, usePathname } from "@/i18n/navigation";
import { can, useSession } from "@/lib/session";
import { useOnline } from "@/lib/useOnline";
import { RequireAuth } from "./RequireAuth";
import { focusRing } from "./styles";

// The admin console's own frame: signed-in only, with its sections as tabs: Catalog and People (Phase 0), the live
// Orders board (Phase 1), Inventory with its own sub-tabs (Phase 2), Shifts (Phase 3), Payments (Phase 4, step 4.5c: shown
// to whoever holds one of its permissions), Analytics (step 4.5e: the dashboards for analytics.view; a person who holds only
// analytics.view_own, every role, is taken to their own figures), the Audit log (step 4.5f: audit.view, the admin and the manager). The
// header's "Admin console" link is the overview. The admin screens are online-only (step 5.2a, plan 10.2 D10: nothing here joins the outbox):
// offline, a notice says so and every control of the screen is disabled (a disabled fieldset; the tabs, links, stay usable).
const SECTIONS: readonly { href: string; key: string; anyOf?: readonly string[]; own?: { unless: string; href: string } }[] = [
  { href: "/admin/catalog", key: "catalog" },
  { href: "/admin/people", key: "people" },
  { href: "/admin/orders", key: "orders" },
  { href: "/admin/inventory", key: "inventory" },
  { href: "/admin/planner", key: "planner" },
  { href: "/admin/shifts", key: "shifts" },
  { href: "/admin/payments", key: "payments", anyOf: ["payments.view", "channels.manage"] },
  { href: "/admin/analytics", key: "analytics", anyOf: ["analytics.view", "analytics.view_own"], own: { unless: "analytics.view", href: "/admin/analytics/me" } },
  { href: "/admin/audit", key: "audit", anyOf: ["audit.view"] },
];

export function AdminFrame({ children }: { children: ReactNode }) {
  const t = useTranslations("admin");
  const pathname = usePathname().replace(/\/+$/, "");
  const me = useSession((s) => s.me);
  const o = useTranslations("outbox");
  const online = useOnline();

  return (
    <RequireAuth>
      <nav aria-label={t("sections")} className="mb-6 flex flex-wrap gap-1 border-b border-neutral-200">
        {SECTIONS.filter(({ anyOf }) => !anyOf || anyOf.some((permission) => can(me, permission))).map(({ href, key, own }) => {
          const current = pathname === href || pathname.startsWith(`${href}/`);
          return (
            <Link
              key={href}
              href={own && !can(me, own.unless) ? own.href : href}
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
      {online ? null : (
        <p role="status" className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {o("admin_offline")}
        </p>
      )}
      <fieldset role="presentation" disabled={!online} className="contents">
        {children}
      </fieldset>
    </RequireAuth>
  );
}
