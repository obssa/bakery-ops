"use client";

import { ChevronDown, Croissant, LayoutGrid, LogOut } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { Suspense, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { useActing } from "@/lib/acting";
import type { Locale } from "@/lib/api/types";
import { frameOf, isCurrent, SCREENS } from "@/lib/screens";
import { useSession } from "@/lib/session";
import { barMode, type BarMode } from "@/lib/shifts";
import { useStationChrome } from "@/lib/station";
import { useTopBarSlots } from "@/lib/topBar";
import { useIsoDay } from "@/lib/useDates";
import { useOnline } from "@/lib/useOnline";
import { DateDisplaySwitch } from "./DateDisplaySwitch";
import { DeviceGate } from "./DeviceGate";
import { ICON_STROKE, SCREEN_ICONS } from "./icons";
import { LanguageSwitcher, LanguageSwitcherWithQuery } from "./LanguageSwitcher";
import { NotificationBell } from "./NotificationBell";
import { PendingIndicator, useSyncState } from "./PendingIndicator";
import { buttonClass, focusRing } from "./styles";
import { Avatar, Chip, IconButton, type Tone } from "./ui";

// The frame around every page (steps 6.2b and 6.3a, fresh-counter.md 3): a top bar, the outbox's band and the page. A station screen (the
// pad, the till, the kitchen and bar displays, the production board, the count, and the start page) has exactly one 68 px bar, as its
// design board does (StationBar): the station mark, the screen's title with its Amharic name and a line with the shift and the person; on
// the right the screen's own buttons (lib/topBar.ts), the sync chip, the language button, the bell, the "Screens" menu and the person's
// menu (the dates' calendar and "Sign out" are in it). The admin console keeps the 6.2b bar with the "Screens" tabs under it until step
// 6.4a rebuilds its frame (AdminTopBar), and draws its sidebar and its own `main` (AdminFrame). The sign-in screen is a split of its own,
// without a bar, and carries "Screens" itself. The waiter pad (the `fill` frame) draws its zones edge to edge and, from 1024 px, in the
// window's height. A page whose calls are refused because this device is not enrolled shows the "not enrolled" screen instead (step
// 5.1b, DeviceGate).

/** The app's mark: a brand-teal square with the line icon, hidden from readers (the name beside it, or the link around it, says it). */
export function AppMark({ size = "md" }: { size?: "md" | "lg" }) {
  return (
    <span
      aria-hidden="true"
      className={`inline-flex shrink-0 items-center justify-center bg-brand-600 text-white ${size === "lg" ? "size-10 rounded-xl" : "size-9 rounded-[10px]"}`}
    >
      <Croissant className={size === "lg" ? "size-[22px]" : "size-5"} strokeWidth={1.8} aria-hidden="true" />
    </span>
  );
}

/**
 * The "Screens" navigation (its name and link names are what the earlier specs read, phase-6 plan 6.0.2): tabs under the station top bar
 * (the current screen underlined in brand teal), or a row of plain links in the sign-in screen's footer.
 */
export function ScreensNav({ look = "tabs" }: { look?: "tabs" | "links" }) {
  const t = useTranslations("nav");
  const pathname = usePathname();

  if (look === "links") {
    return (
      <nav aria-label={t("main")}>
        <ul className="flex flex-wrap items-center gap-1">
          {SCREENS.map(({ href, key }) => {
            const Icon = SCREEN_ICONS[key];
            return (
              <li key={href}>
                <Link
                  href={href}
                  className={`inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900 ${focusRing}`}
                >
                  <Icon className="size-4 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
                  {t(key)}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>
    );
  }

  return (
    <nav aria-label={t("main")} className="flex gap-1 overflow-x-auto px-2 sm:px-4">
      {SCREENS.map(({ href, key }) => {
        const current = isCurrent(pathname, href);
        const Icon = SCREEN_ICONS[key];
        return (
          <Link
            key={href}
            href={href}
            aria-current={current ? "page" : undefined}
            className={`inline-flex min-h-12 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-[15px] font-semibold motion-safe:transition-colors motion-safe:duration-150 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-brand-600/35 ${
              current ? "border-brand-600 text-brand-700" : "border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900"
            }`}
          >
            <Icon className="size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t(key)}
          </Link>
        );
      })}
    </nav>
  );
}

// The shift's tone and word in each state of the shift bar (the shift bar's own badge, ShiftBar.tsx): amber while none runs, blue for a
// count in progress, brand teal while open.
const SHIFT_CHIP: Record<BarMode, { tone: Tone; key: string }> = {
  idle: { tone: "amber", key: "badge_none" },
  opening: { tone: "blue", key: "badge_opening" },
  open: { tone: "brand", key: "badge_open" },
  counting: { tone: "blue", key: "badge_counting" },
};

const SHIFT_DOT: Record<Tone, string> = {
  neutral: "bg-neutral-500",
  brand: "bg-brand-600",
  amber: "bg-amber-700",
  red: "bg-red-700",
  blue: "bg-blue-700",
  green: "bg-green-700",
};

/** The station the screen works at and its shift, as the screen's shift feed read it (lib/station.ts useStationChrome). */
function useStationShift(): { name: string | null; nameAm: string | null; shift: { tone: Tone; text: string } | null } {
  const t = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const isoDay = useIsoDay();
  const chrome = useStationChrome((s) => s.chrome);
  if (!chrome) return { name: null, nameAm: null, shift: null };

  const { station, shift } = chrome;
  let chip: { tone: Tone; text: string } | null = null;
  if (shift !== undefined) {
    const { tone, key } = SHIFT_CHIP[barMode(shift)];
    const label = shift
      ? t("shift_label", { name: shift.template ? (locale === "am" ? shift.template.name_am : shift.template.name_en) : t("ad_hoc"), date: isoDay(shift.date) })
      : null;
    chip = { tone, text: label ? `${t(key)} · ${label}` : t(key) };
  }
  return { name: locale === "am" ? station.name_am : station.name_en, nameAm: station.name_am, shift: chip };
}

/** Whether this tablet's writes reached the server, in a word: online and sent, waiting (offline or not), or refused writes to look at. */
function SyncChip() {
  const t = useTranslations("shell");
  const online = useOnline();
  const { here, elsewhere, conflicts, held } = useSyncState();
  const waiting = here + elsewhere;

  let tone: Tone = "brand";
  let text = t("sync_online");
  if (conflicts > 0) {
    tone = "amber";
    text = t("sync_conflicts", { count: conflicts });
  } else if (!online) {
    tone = "neutral";
    text = waiting > 0 ? t("sync_offline_waiting", { count: waiting }) : t("sync_offline");
  } else if (waiting > 0) {
    // a queue a device refusal holds (the tray says why) is amber, one that is only waiting its turn neutral
    tone = held ? "amber" : "neutral";
    text = t("sync_sending", { count: waiting });
  }
  return (
    <Chip tone={tone} dot className="whitespace-nowrap">
      {text}
    </Chip>
  );
}

/** A menu that opens under its button: closed by Escape, a tap beside it, or a move to another page. */
function useMenu() {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const pathname = usePathname();
  useEffect(() => setOpen(false), [pathname]);
  useEffect(() => {
    if (!open) return;
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }
    function onPointer(event: PointerEvent) {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);
  return { open, setOpen, box };
}

const MENU_PANEL = "absolute right-0 top-full z-40 mt-2 rounded-2xl border border-neutral-200 bg-white p-2 text-neutral-900 shadow-float";

/** The bar's own buttons (the menus), on white or, at night, on the kitchen display's dark ground. */
function barButton(night: boolean): string {
  return `inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl border text-sm font-semibold ${
    night ? "border-night-border bg-night-ground text-night-text hover:bg-night-divider" : "border-neutral-200 bg-white text-neutral-900 hover:bg-neutral-100"
  } ${focusRing}`;
}

/**
 * The "Screens" menu (step 6.3a): a grid button in the bar; open, it holds the "Screens" navigation with the same link names (phase-6 plan
 * 6.0.2), the current one in brand teal.
 */
function ScreensMenu({ night }: { night: boolean }) {
  const t = useTranslations("nav");
  const pathname = usePathname();
  const id = useId();
  const { open, setOpen, box } = useMenu();
  return (
    <div ref={box} className="relative">
      <button
        type="button"
        aria-label={t("main")}
        title={t("main")}
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        onClick={() => setOpen((now) => !now)}
        className={`${barButton(night)} w-10`}
      >
        <LayoutGrid className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
      </button>
      {open ? (
        <nav id={id} aria-label={t("main")} className={`${MENU_PANEL} w-64`}>
          <ul className="space-y-0.5">
            {SCREENS.map(({ href, key }) => {
              const current = isCurrent(pathname, href);
              const Icon = SCREEN_ICONS[key];
              return (
                <li key={href}>
                  <Link
                    href={href}
                    aria-current={current ? "page" : undefined}
                    onClick={() => setOpen(false)}
                    className={`flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold ${current ? "bg-brand-50 text-brand-700" : "text-neutral-800 hover:bg-neutral-100"} ${focusRing}`}
                  >
                    <Icon className="size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
                    {t(key)}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      ) : null}
    </div>
  );
}

/**
 * The person (step 6.3a): initials, name and role, as the boards have them; on the pad, while a waiter has it, the waiter ("Acting:
 * <name>" to readers, as the pad always said it) and "On the pad". A tap opens the menu with the dates' calendar (step 4.5f) and "Sign
 * out"; signed out, "Sign in".
 */
function AccountMenu({ fill, night }: { fill: boolean; night: boolean }) {
  const t = useTranslations();
  const router = useRouter();
  const status = useSession((s) => s.status);
  const me = useSession((s) => s.me);
  const signOut = useSession((s) => s.signOut);
  const acting = useActing((s) => s.user);
  const id = useId();
  const { open, setOpen, box } = useMenu();

  if (status === "unknown") return <span className="inline-block h-10 w-24" aria-hidden="true" />;
  if (status !== "signed-in" || !me) {
    return (
      <Link href="/login" className={buttonClass({ variant: "secondary", size: "md" })}>
        {t("nav.login")}
      </Link>
    );
  }

  const waiter = fill && acting ? acting : null;
  const name = waiter ? waiter.name : me.user.name;
  const role = me.user.roles?.[0];
  const roleText = waiter ? t("pad.acting_role") : role ? t(`people.roles.${role}`) : null;
  return (
    <div ref={box} className="relative">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        onClick={() => setOpen((now) => !now)}
        className={`flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 ${night ? "hover:bg-night-divider" : "hover:bg-neutral-100"} ${focusRing}`}
      >
        <Avatar name={name} />
        <span className="hidden min-w-0 max-w-40 flex-col text-left leading-tight lg:flex">
          <span className={`truncate text-sm font-semibold ${night ? "text-night-text" : "text-neutral-900"}`}>
            {waiter ? <span className="sr-only">{t("pad.acting_prefix")} </span> : null}
            {name}
          </span>
          {roleText ? <span className={`truncate text-xs ${night ? "text-night-text-muted" : "text-neutral-600"}`}>{roleText}</span> : null}
        </span>
        {/* below 1024 px the name is not shown: readers still hear whose menu it is */}
        <span className="sr-only lg:hidden">{name}</span>
        <ChevronDown className={`size-4 ${night ? "text-night-text-muted" : "text-neutral-500"}`} strokeWidth={2} aria-hidden="true" />
      </button>
      {open ? (
        <div id={id} className={`${MENU_PANEL} w-72 space-y-3 p-4`}>
          <div className="leading-tight">
            <p className="truncate text-[15px] font-semibold">{me.user.name}</p>
            {role ? <p className="truncate text-[13px] text-neutral-600">{t(`people.roles.${role}`)}</p> : null}
          </div>
          <DateDisplaySwitch />
          <button
            type="button"
            onClick={async () => {
              setOpen(false);
              await signOut();
              router.replace("/login");
            }}
            className={`${buttonClass({ variant: "secondary", size: "md" })} w-full`}
          >
            <LogOut className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t("nav.sign_out")}
          </button>
        </div>
      ) : null}
    </div>
  );
}

/**
 * The one top bar of a station screen (step 6.3a; fresh-counter.md 3 and the Pad, Kitchen, Till and Production boards), 68 px: on the left
 * the station mark (the way home), the screen's title with its Amharic name beside it on the English page, and a line under it with the
 * shift and the person; on the right the screen's extras (lib/topBar.ts), the sync chip, the language button, the bell, the "Screens" menu
 * and the person's menu. A screen names itself with `useStationHeader`; without it the bar shows the station its shift feed published, or
 * the app's name. `night` is the kitchen display's look.
 */
function StationBar({ fill }: { fill: boolean }) {
  const t = useTranslations();
  const locale = useLocale() as Locale;
  const signedIn = useSession((s) => s.status === "signed-in");
  const me = useSession((s) => s.me);
  const acting = useActing((s) => s.user);
  const header = useTopBarSlots((s) => s.header);
  const setActions = useTopBarSlots((s) => s.setActions);
  const station = useStationShift();

  const night = header?.night === true;
  const title = header?.title ?? station.name ?? t("app.name");
  const titleAm = locale === "en" ? (header?.titleAm ?? station.nameAm) : null;
  const person = fill && acting ? acting.name : me?.user.name;
  const subtitle = header?.subtitle ?? ([station.shift?.text, person].filter(Boolean).join(" · ") || null);
  const bell = header?.bell !== false;

  return (
    <header className={`border-b ${night ? "border-night-border bg-night-surface" : "border-neutral-200 bg-white"}`}>
      <div className="flex min-h-17 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6 md:h-17 md:flex-nowrap md:py-0">
        <div className="flex min-w-0 flex-1 items-center gap-3.5">
          <Link href="/" className={`flex shrink-0 items-center rounded-xl ${focusRing}`}>
            <AppMark />
            <span className="sr-only">{t("app.name")}</span>
          </Link>
          <div className="flex min-w-0 flex-col">
            <p className="flex min-w-0 items-baseline gap-2">
              <span className={`truncate text-lg font-semibold leading-tight ${night ? "text-night-text" : "text-neutral-900"}`}>{title}</span>
              {titleAm && titleAm !== title ? (
                <span lang="am" className={`truncate text-[15px] font-medium ${night ? "text-night-text-muted" : "text-neutral-600"}`}>
                  <span aria-hidden="true">· </span>
                  {titleAm}
                </span>
              ) : null}
            </p>
            {subtitle ? (
              <p className={`flex min-w-0 items-center gap-1.5 text-[13px] ${night ? "text-night-text-muted" : "text-neutral-600"}`}>
                {station.shift && !header?.subtitle ? <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${SHIFT_DOT[station.shift.tone]}`} /> : null}
                <span className="truncate">{subtitle}</span>
              </p>
            ) : null}
          </div>
        </div>
        {/* on a phone the right-hand group wraps inside the window rather than pushing past it */}
        <div className="flex max-w-full flex-wrap items-center gap-2 md:shrink-0">
          <div ref={setActions} className="contents" />
          {signedIn ? <SyncChip /> : null}
          <Suspense fallback={<LanguageSwitcher look="toggle" />}>
            <LanguageSwitcherWithQuery look="toggle" />
          </Suspense>
          {signedIn && bell ? <NotificationBell /> : null}
          <ScreensMenu night={night} />
          <AccountMenu fill={fill} night={night} />
        </div>
      </div>
    </header>
  );
}

/** The person signed in on this device on an admin page: initials, name and role (from 1280 px), and "Sign out"; or "Sign in". */
function Account() {
  const t = useTranslations();
  const router = useRouter();
  const status = useSession((s) => s.status);
  const me = useSession((s) => s.me);
  const signOut = useSession((s) => s.signOut);

  if (status === "unknown") return <span className="inline-block h-12 w-24" aria-hidden="true" />;

  if (status !== "signed-in" || !me) {
    return (
      <Link href="/login" className={buttonClass({ variant: "secondary" })}>
        {t("nav.login")}
      </Link>
    );
  }

  const role = me.user.roles?.[0];
  return (
    <div className="flex items-center gap-2.5 pl-1">
      <Avatar name={me.user.name} />
      <span className="hidden min-w-0 max-w-40 flex-col leading-tight xl:flex">
        <span className="truncate text-sm font-semibold text-neutral-900">{me.user.name}</span>
        {role ? <span className="truncate text-xs text-neutral-600">{t(`people.roles.${role}`)}</span> : null}
      </span>
      <IconButton
        label={t("nav.sign_out")}
        title={t("nav.sign_out")}
        variant="ghost"
        onClick={async () => {
          await signOut();
          router.replace("/login");
        }}
      >
        <LogOut className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
      </IconButton>
    </div>
  );
}

/**
 * The admin console's bar (step 6.2b; step 6.4a rebuilds the admin frame): the mark and the app's name, the sync chip, the calendar, the
 * language, the bell and the person, then the "Screens" tabs under it.
 */
function AdminTopBar() {
  const t = useTranslations("app");
  const signedIn = useSession((s) => s.status === "signed-in");

  return (
    <header className="border-b border-neutral-200 bg-white">
      <div className="flex min-h-17 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6 md:h-17 md:flex-nowrap md:py-0">
        <div className="flex min-w-0 flex-1 items-center gap-3.5">
          <Link href="/" className={`flex shrink-0 items-center gap-3 rounded-xl ${focusRing}`}>
            <AppMark />
            <span className="text-lg font-bold tracking-tight text-neutral-900">{t("name")}</span>
          </Link>
        </div>
        <div className="flex max-w-full flex-wrap items-center gap-2 md:shrink-0">
          {signedIn ? <SyncChip /> : null}
          {signedIn ? <DateDisplaySwitch /> : null}
          <Suspense fallback={<LanguageSwitcher look="toggle" />}>
            <LanguageSwitcherWithQuery look="toggle" />
          </Suspense>
          {signedIn ? <NotificationBell /> : null}
          <Account />
        </div>
      </div>
      <ScreensNav />
    </header>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const t = useTranslations("app");
  const frame = frameOf(usePathname());

  // `fill` (the waiter pad): from 1024 px the frame is the window's height, so the page's zones scroll on their own under the bar; below
  // it the page flows and scrolls as any other. Its main has no padding: the screen draws its zones edge to edge. A screen that is not
  // drawn yet there (the sign-in check, a refusal, the not-enrolled screen) keeps the padding of every other station page. The main never
  // gets shorter than 34rem: a tall band above it (the conflict tray) makes the page scroll instead of crushing the order panel.
  const fill = frame === "fill";
  return (
    <div className={`flex min-h-screen flex-col ${fill ? "lg:h-dvh lg:min-h-0" : ""}`}>
      <a
        href="#content"
        className={`sr-only rounded-xl bg-white px-4 py-3 text-base font-semibold focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 ${focusRing}`}
      >
        {t("skip_to_content")}
      </a>
      {frame === "login" ? null : frame === "admin" ? <AdminTopBar /> : <StationBar fill={fill} />}
      <PendingIndicator />
      {frame === "admin" ? (
        children
      ) : (
        <main
          id="content"
          className={
            frame === "login"
              ? "flex flex-1 flex-col"
              : fill
                ? "flex w-full flex-1 flex-col px-4 py-6 sm:px-6 has-[[data-shell-fill]]:p-0 lg:min-h-[34rem] lg:overflow-y-auto"
                : "w-full flex-1 px-4 py-6 sm:px-6"
          }
        >
          <DeviceGate>{children}</DeviceGate>
        </main>
      )}
    </div>
  );
}
