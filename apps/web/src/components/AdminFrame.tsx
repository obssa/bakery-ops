"use client";

import { Menu, WifiOff, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Link, usePathname } from "@/i18n/navigation";
import type { Me } from "@/lib/api/types";
import { isCurrent } from "@/lib/screens";
import { can, useSession } from "@/lib/session";
import { useOnline } from "@/lib/useOnline";
import { DeviceGate } from "./DeviceGate";
import { ICON_STROKE, SECTION_ICONS } from "./icons";
import { RequireAuth } from "./RequireAuth";
import { buttonClass, focusRing } from "./styles";
import { IconButton } from "./ui";

// The admin console's own frame (step 6.2b, fresh-counter.md 4.1), under the station top bar: a 264 px white sidebar with the sections in
// groups, each with its 18 px line icon, the current one in brand-50 with brand-700 text; below 1024 px the sidebar is a drawer behind the
// menu button of a 60 px bar. The content column is at most 1160 px wide, with 32 px of padding (16 on a phone). Signed-in only.
//
// The sections and who sees them are as they were: Catalog and People (Phase 0), the live Orders board (Phase 1), Inventory with its own
// sub-tabs (Phase 2), Shifts (Phase 3), Payments (Phase 4, step 4.5c: shown to whoever holds one of its permissions), Analytics (step 4.5e:
// the dashboards for analytics.view; a person who holds only analytics.view_own, every role, is taken to their own figures), the Audit log
// (step 4.5f: audit.view, the admin and the manager), System (step 5.5c: system.view, the admin: the host's health). The navigation is still
// "Admin sections" with the same link names. The overview is the top bar's "Admin console" link, as before. The spec's rows without a page
// here wait (Refunds, phase-6 plan 10.2 Q2), and so do the ones that are other pages' links already: Today is the overview (step 6.4a),
// Production is the station screen in "Screens", Devices is People's own link. The branch's name waits for an API that reads it (Q3).
//
// The admin screens are online-only (step 5.2a, plan 10.2 D10: nothing here joins the outbox): offline, a notice says so and every control
// of the screen is disabled (a disabled fieldset; the sections, links, stay usable).

interface Section {
  href: string;
  key: string;
  anyOf?: readonly string[];
  own?: { unless: string; href: string };
}

const SECTIONS: readonly Section[] = [
  { href: "/admin/catalog", key: "catalog" },
  { href: "/admin/people", key: "people" },
  { href: "/admin/orders", key: "orders" },
  { href: "/admin/inventory", key: "inventory" },
  { href: "/admin/planner", key: "planner" },
  { href: "/admin/shifts", key: "shifts" },
  { href: "/admin/payments", key: "payments", anyOf: ["payments.view", "channels.manage"] },
  { href: "/admin/analytics", key: "analytics", anyOf: ["analytics.view", "analytics.view_own"], own: { unless: "analytics.view", href: "/admin/analytics/me" } },
  { href: "/admin/audit", key: "audit", anyOf: ["audit.view"] },
  { href: "/admin/system", key: "system", anyOf: ["system.view"] },
];

/** The sidebar's groups (fresh-counter.md 4.1), each a list of section keys; the last one has no heading. */
const GROUPS: readonly { label: string | null; keys: readonly string[] }[] = [
  { label: "group_sell", keys: ["orders", "payments"] },
  { label: "group_make", keys: ["catalog", "inventory", "planner"] },
  { label: "group_team", keys: ["people", "shifts"] },
  { label: "group_insight", keys: ["analytics", "audit"] },
  { label: null, keys: ["system"] },
];

/** The sections this person sees, grouped; a group whose sections are all hidden is left out. */
function groupsFor(me: Me | null) {
  const shown = SECTIONS.filter(({ anyOf }) => !anyOf || anyOf.some((permission) => can(me, permission)));
  return GROUPS.map(({ label, keys }) => ({ label, sections: keys.map((key) => shown.find((s) => s.key === key)).filter((s): s is Section => !!s) })).filter(
    (group) => group.sections.length > 0,
  );
}

function SectionsNav({ onFollow }: { onFollow?: () => void }) {
  const t = useTranslations("admin");
  const pathname = usePathname();
  const me = useSession((s) => s.me);
  const id = useId();

  return (
    <nav aria-label={t("sections")} className="flex flex-col gap-1.5">
      {groupsFor(me).map(({ label, sections }, index) => (
        <div key={label ?? index} className="flex flex-col gap-0.5">
          {label ? (
            <p id={`${id}-${label}`} className="px-3 pb-1 pt-2.5 text-xs font-semibold uppercase tracking-[0.08em] text-neutral-600">
              {t(label)}
            </p>
          ) : null}
          <ul aria-labelledby={label ? `${id}-${label}` : undefined} className="flex flex-col gap-0.5">
            {sections.map(({ href, key, own }) => {
              const current = isCurrent(pathname, href);
              const Icon = SECTION_ICONS[key];
              return (
                <li key={href}>
                  <Link
                    href={own && !can(me, own.unless) ? own.href : href}
                    aria-current={current ? "page" : undefined}
                    onClick={onFollow}
                    className={`flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] motion-safe:transition-colors motion-safe:duration-150 ${
                      current ? "bg-brand-50 font-semibold text-brand-700" : "font-medium text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
                    } ${focusRing}`}
                  >
                    {Icon ? <Icon className="size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" /> : null}
                    {t(key)}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

/** Below 1024 px the sidebar is a drawer; it closes on Escape, on a tap beside it, on a link, and when the window grows past 1024 px. */
function useDrawer() {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();
  const trigger = useRef<HTMLButtonElement>(null);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => setOpen(false), [pathname]);

  useEffect(() => {
    if (!open) return;
    panel.current?.focus();
    const wide = window.matchMedia("(min-width: 1024px)");
    const close = () => setOpen(false);
    function onKey(event: KeyboardEvent) {
      if (event.key !== "Escape") return;
      setOpen(false);
      trigger.current?.focus();
    }
    document.addEventListener("keydown", onKey);
    wide.addEventListener("change", close);
    return () => {
      document.removeEventListener("keydown", onKey);
      wide.removeEventListener("change", close);
    };
  }, [open]);

  return { open, setOpen, trigger, panel };
}

export function AdminFrame({ children }: { children: ReactNode }) {
  const t = useTranslations("admin");
  const o = useTranslations("outbox");
  const signedIn = useSession((s) => s.status === "signed-in" && s.me !== null);
  const online = useOnline();
  const drawer = useDrawer();
  const panelId = useId();

  return (
    <div className="flex min-w-0 flex-1">
      {drawer.open ? <div aria-hidden="true" className="fixed inset-0 z-40 bg-neutral-900/40 lg:hidden" onClick={() => drawer.setOpen(false)} /> : null}
      <div
        id={panelId}
        ref={drawer.panel}
        tabIndex={drawer.open ? -1 : undefined}
        {...(drawer.open ? { role: "dialog", "aria-modal": true, "aria-label": t("sections") } : {})}
        className={`${
          drawer.open ? "fixed inset-y-0 left-0 z-50 flex w-[min(264px,85vw)] shadow-float outline-none motion-safe:animate-sidebar-in" : "hidden"
        } flex-col border-r border-neutral-200 bg-white lg:static lg:z-auto lg:flex lg:w-66 lg:shrink-0 lg:animate-none lg:shadow-none`}
      >
        <div className="flex max-h-screen flex-col gap-3 overflow-y-auto px-3.5 py-5 lg:sticky lg:top-0">
          {drawer.open ? (
            <div className="flex justify-end lg:hidden">
              <IconButton
                label={t("close_sections")}
                onClick={() => {
                  drawer.setOpen(false);
                  drawer.trigger.current?.focus();
                }}
              >
                <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
              </IconButton>
            </div>
          ) : null}
          {signedIn ? <SectionsNav onFollow={() => drawer.setOpen(false)} /> : null}
        </div>
      </div>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex h-15 items-center gap-3 border-b border-neutral-200 bg-white px-4 sm:px-6 lg:hidden">
          <button
            ref={drawer.trigger}
            type="button"
            aria-label={t("open_sections")}
            aria-expanded={drawer.open}
            aria-controls={panelId}
            onClick={() => drawer.setOpen(true)}
            className={buttonClass({ variant: "secondary", size: "md", square: true })}
          >
            <Menu className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
          </button>
          <span className="truncate text-base font-semibold text-neutral-900">{t("title")}</span>
        </div>
        <main id="content" className="w-full min-w-0 flex-1 px-4 py-6 sm:px-6 lg:p-8">
          <div className="mx-auto w-full max-w-[1160px]">
            <RequireAuth>
              <DeviceGate>
                {online ? null : (
                  <p role="status" className="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
                    <WifiOff className="mt-0.5 size-5 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
                    <span>{o("admin_offline")}</span>
                  </p>
                )}
                <fieldset role="presentation" disabled={!online} className="contents">
                  {children}
                </fieldset>
              </DeviceGate>
            </RequireAuth>
          </div>
        </main>
      </div>
    </div>
  );
}
