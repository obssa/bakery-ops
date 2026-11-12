"use client";

import { ChevronsUpDown, LogOut, Menu, WifiOff, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { Suspense, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { canOpen, stationScreensFor } from "@/lib/access";
import type { Me } from "@/lib/api/types";
import { isCurrent } from "@/lib/screens";
import { useSession } from "@/lib/session";
import { useOnline } from "@/lib/useOnline";
import { AppMark, BrandName, SyncChip } from "./AppShell";
import { DateDisplaySwitch } from "./DateDisplaySwitch";
import { DeviceGate } from "./DeviceGate";
import { ICON_STROKE, SCREEN_ICONS, SECTION_ICONS } from "./icons";
import { LanguageSwitcher, LanguageSwitcherWithQuery } from "./LanguageSwitcher";
import { NotificationBell } from "./NotificationBell";
import { PendingIndicator } from "./PendingIndicator";
import { RequireAuth } from "./RequireAuth";
import { buttonClass, focusRing } from "./styles";
import { Avatar, IconButton } from "./ui";

// The admin console's frame (fresh-counter.md 4.1, the Admin and Catalog boards; step 6.2b, and step 6.4a without the station top bar): an
// admin page is the 264 px white sidebar and the content, nothing above them. The sidebar holds, top to bottom: the app's mark and name (the
// way home); the sections in groups, each row 44 px with its 18 px line icon, the current one brand-50 with brand-700 text, Today first;
// "Stations", the "Screens" navigation of the station screens; and at its foot the sync chip and the person's card, with the bell beside
// it and, behind the card, the account menu: the calendar ("Dates"), the language and "Sign out". From 1024 px the sidebar holds the
// window's height and its sections scroll between the mark and the card; below 1024 px it is a drawer behind the menu button of a 60 px
// bar. The content column is at most 1160 px wide, with 32 px of padding (16 on a phone), under the outbox's band. Signed-in only.
//
// The sections and who sees them are as they were: Catalog and People (Phase 0), the live Orders board (Phase 1), Inventory with its own
// sub-tabs (Phase 2), Shifts (Phase 3), Payments (Phase 4, step 4.5c: shown to whoever holds one of its permissions), Analytics (step 4.5e:
// the dashboards for analytics.view; a person who holds only analytics.view_own, every role, is taken to their own figures), the Audit log
// (step 4.5f: audit.view, the admin and the manager), System (step 5.5c: system.view, the admin: the host's health), Today (step 6.4a,
// the console's first page, for everyone), and Tables in Sell after Payments (the Phase 6 follow-up: tables.manage, the admin and the manager). The navigation is still "Admin sections" with the same link names. The spec's rows without a page
// here wait (Refunds, phase-6 plan 10.2 Q2), and so do the ones that are other pages' links already: Production is the station screen in
// "Screens", Devices is People's own link. The branch's name waits for an API every role may read that names it (Q3).
//
// The admin screens are online-only (step 5.2a, plan 10.2 D10: nothing here joins the outbox): offline, a notice says so and every control
// of the screen is disabled (a disabled fieldset; the sections, links, stay usable).

interface Section {
  href: string;
  key: string;
  /** the page the row opens instead when the person cannot open `href` but can open this one */
  fallback?: string;
  /** current on this page only, not on the pages under it (Today is /admin, the console's first page) */
  exact?: boolean;
}

// Who sees a section is who can open it (ADR 0027, lib/access.ts canOpen): the page's own guard decides, so no row ends on "Your account
// cannot open this page". Analytics opens the dashboards for analytics.view and a person's own figures for analytics.view_own; Payments
// opens the channels for a holder of channels.manage without payments.view.
const SECTIONS: readonly Section[] = [
  { href: "/admin", key: "today", exact: true },
  { href: "/admin/catalog", key: "catalog" },
  { href: "/admin/people", key: "people" },
  { href: "/admin/orders", key: "orders" },
  { href: "/admin/inventory", key: "inventory" },
  { href: "/admin/planner", key: "planner" },
  { href: "/admin/shifts", key: "shifts" },
  { href: "/admin/payments", key: "payments", fallback: "/admin/payments/channels" },
  { href: "/admin/tables", key: "tables" },
  { href: "/admin/analytics", key: "analytics", fallback: "/admin/analytics/me" },
  { href: "/admin/audit", key: "audit" },
  { href: "/admin/system", key: "system" },
];

/** The sidebar's groups (fresh-counter.md 4.1), each a list of section keys; Today and System have no heading. */
const GROUPS: readonly { label: string | null; keys: readonly string[] }[] = [
  { label: null, keys: ["today"] },
  { label: "group_sell", keys: ["orders", "payments", "tables"] },
  { label: "group_make", keys: ["catalog", "inventory", "planner"] },
  { label: "group_team", keys: ["people", "shifts"] },
  { label: "group_insight", keys: ["analytics", "audit"] },
  { label: null, keys: ["system"] },
];

/** Where a section's row leads for this person: its page, else its fallback; null when they can open neither. */
function targetOf(me: Me | null, { href, fallback }: Section): string | null {
  if (canOpen(me, href)) return href;
  return fallback && canOpen(me, fallback) ? fallback : null;
}

/** The sections this person sees, grouped, each with where it leads; a group whose sections are all hidden is left out. */
export function groupsFor(me: Me | null) {
  const shown = SECTIONS.flatMap((section) => {
    const target = targetOf(me, section);
    return target ? [{ ...section, target }] : [];
  });
  return GROUPS.map(({ label, keys }) => ({ label, sections: keys.flatMap((key) => shown.filter((s) => s.key === key)) })).filter((group) => group.sections.length > 0);
}

const GROUP_LABEL = "px-3 pb-1 pt-2.5 text-xs font-semibold uppercase tracking-[0.08em] text-neutral-600";

function rowClass(current: boolean): string {
  return `flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] motion-safe:transition-colors motion-safe:duration-150 ${
    current ? "bg-brand-50 font-semibold text-brand-700" : "font-medium text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
  } ${focusRing}`;
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
            <p id={`${id}-${label}`} className={GROUP_LABEL}>
              {t(label)}
            </p>
          ) : null}
          <ul aria-labelledby={label ? `${id}-${label}` : undefined} className="flex flex-col gap-0.5">
            {sections.map(({ href, key, target, exact }) => {
              const path = pathname.replace(/\/+$/, "") || "/";
              const current = exact ? path === href : isCurrent(pathname, href);
              const Icon = SECTION_ICONS[key];
              return (
                <li key={href}>
                  <Link href={target} aria-current={current ? "page" : undefined} onClick={onFollow} className={rowClass(current)}>
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

/**
 * "Stations": the station screens, the same "Screens" navigation and link names the station top bar and the sign-in screen carry. The
 * navigation's name is "Screens"; its list is not labelled "Stations" as well, since a page's getByLabel("Station") would find it.
 */
function StationsNav({ onFollow }: { onFollow?: () => void }) {
  const t = useTranslations();
  const me = useSession((s) => s.me);
  const screens = stationScreensFor(me);
  if (screens.length === 0) return null;
  return (
    <div className="mt-1.5 flex flex-col gap-0.5">
      <p className={GROUP_LABEL}>{t("admin.group_stations")}</p>
      <nav aria-label={t("nav.main")}>
        <ul className="flex flex-col gap-0.5">
          {screens.map(({ href, key }) => {
            const Icon = SCREEN_ICONS[key];
            return (
              <li key={href}>
                <Link href={href} onClick={onFollow} className={rowClass(false)}>
                  <Icon className="size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
                  {t(`nav.${key}`)}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>
    </div>
  );
}

/**
 * The person at the foot of the sidebar (the board's user card: initials, name and role), with the bell beside it. The card opens the
 * account menu above it: the calendar ("Dates"), the language (the other language's name, as the top bar's button) and "Sign out", each with
 * the name it had in the station top bar. Closed by Escape, a tap outside, or a choice that leaves the page.
 */
function AccountCard() {
  const t = useTranslations();
  const router = useRouter();
  const me = useSession((s) => s.me);
  const signOut = useSession((s) => s.signOut);
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const menuId = useId();
  const pathname = usePathname();

  useEffect(() => setOpen(false), [pathname]);
  useEffect(() => {
    if (!open) return;
    function onKey(event: KeyboardEvent) {
      if (event.key !== "Escape") return;
      setOpen(false);
      trigger.current?.focus();
    }
    function onPointer(event: PointerEvent) {
      if (root.current && !root.current.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  if (!me) return null;
  const role = me.user.roles?.[0];

  return (
    <div ref={root} className="relative flex items-center gap-1.5 rounded-[14px] border border-neutral-200 bg-white p-1.5">
      <button
        ref={trigger}
        type="button"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((v) => !v)}
        className={`flex min-h-12 min-w-0 flex-1 items-center gap-2.5 rounded-xl px-1.5 text-left hover:bg-neutral-50 motion-safe:transition-colors motion-safe:duration-150 ${open ? "bg-neutral-50" : ""} ${focusRing}`}
      >
        <Avatar name={me.user.name} />
        <span className="flex min-w-0 flex-1 flex-col leading-tight">
          <span className="truncate text-sm font-semibold text-neutral-900">{me.user.name}</span>
          {role ? <span className="truncate text-xs text-neutral-600">{` ${t(`people.roles.${role}`)}`}</span> : null}
          <span className="sr-only">{`, ${t("admin.account_menu")}`}</span>
        </span>
        <ChevronsUpDown className="size-4 shrink-0 text-neutral-500" strokeWidth={2} aria-hidden="true" />
      </button>
      <NotificationBell placement="above" />

      {open ? (
        <div
          id={menuId}
          role="group"
          aria-label={t("admin.account_menu")}
          className="absolute inset-x-0 bottom-full z-30 mb-2 space-y-4 rounded-2xl border border-neutral-200 bg-white p-4 shadow-float"
        >
          <DateDisplaySwitch look="field" />
          <div className="flex items-center justify-between gap-3">
            <span className="text-[13px] font-semibold text-neutral-700">{t("common.language")}</span>
            <Suspense fallback={<LanguageSwitcher look="toggle" />}>
              <LanguageSwitcherWithQuery look="toggle" />
            </Suspense>
          </div>
          <button
            type="button"
            onClick={async () => {
              setOpen(false);
              await signOut();
              router.replace("/login");
            }}
            className={`${buttonClass({ variant: "secondary", size: "md" })} w-full`}
          >
            <LogOut className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t("nav.sign_out")}
          </button>
        </div>
      ) : null}
    </div>
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

/** The app's mark and name, the way home (the start page), as the board's sidebar opens. */
function Brand() {
  return (
    <Link href="/" className={`flex min-w-0 items-center gap-3 rounded-xl px-2 ${focusRing}`}>
      <AppMark />
      <BrandName className="truncate text-lg font-bold tracking-tight text-neutral-900" />
    </Link>
  );
}

export function AdminFrame({ children }: { children: ReactNode }) {
  const t = useTranslations("admin");
  const o = useTranslations("outbox");
  const signedIn = useSession((s) => s.status === "signed-in" && s.me !== null);
  const online = useOnline();
  const drawer = useDrawer();
  const panelId = useId();
  const follow = () => drawer.setOpen(false);

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
        } flex-col border-r border-neutral-200 bg-white lg:sticky lg:top-0 lg:z-20 lg:flex lg:h-dvh lg:w-66 lg:shrink-0 lg:animate-none lg:shadow-none`}
      >
        <div className="flex items-center justify-between gap-2 px-3.5 pb-3.5 pt-5">
          <Brand />
          {drawer.open ? (
            <IconButton
              label={t("close_sections")}
              size="md"
              className="lg:hidden"
              onClick={() => {
                drawer.setOpen(false);
                drawer.trigger.current?.focus();
              }}
            >
              <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
            </IconButton>
          ) : null}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-3.5 pb-3">
          {signedIn ? (
            <>
              <SectionsNav onFollow={follow} />
              <StationsNav onFollow={follow} />
            </>
          ) : null}
        </div>
        {signedIn ? (
          <div className="space-y-2.5 border-t border-neutral-200 px-3.5 pb-5 pt-3">
            <SyncChip />
            <AccountCard />
          </div>
        ) : null}
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
          <Brand />
        </div>
        <PendingIndicator />
        <main id="content" className="w-full min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8 xl:px-10">
          <div className="w-full max-w-[1160px]">
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
