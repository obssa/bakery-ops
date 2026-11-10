"use client";

import { Croissant, LayoutGrid, LogOut } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { Suspense, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { useActing } from "@/lib/acting";
import { useBrand, useBrandTitle } from "@/lib/brand";
import type { Locale } from "@/lib/api/types";
import { frameOf, isCurrent, isNightScreen, SCREENS } from "@/lib/screens";
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
import { Avatar, Chip, type Tone } from "./ui";

// The frame around every page (steps 6.2b, 6.3a and 6.4a, fresh-counter.md 3 and 4.1). A station screen (the pad, the till, the kitchen and
// bar displays, the production board, the count, and the start page) has exactly one 68 px bar, as its design board does (StationBar):
// the station mark, the screen's title with its Amharic name and a line with the shift and the person; on the right the screen's own
// buttons (lib/topBar.ts), the sync chip, the language button, the bell, the "Screens" menu and the person's menu (the dates' calendar and
// "Sign out" are in it); then the place for a screen's band under the bar (the shift's attention strip, in the page's flow), the outbox's
// band and the page. The admin console has no top bar (step 6.4a, the Admin board): it is its
// sidebar and the content, and AdminFrame draws both, its `main`, the outbox's band and, in the sidebar, the sync chip, the bell, the
// account menu and "Screens". The sign-in screen is a split of its own, without a bar, and carries "Screens" itself. The waiter pad (the
// `fill` frame) draws its zones edge to edge and, from 1024 px, in the window's height. A page whose calls are refused because this device
// is not enrolled shows the "not enrolled" screen instead (step 5.1b, DeviceGate). On the kitchen and bar displays the whole frame, the bar
// included, is kitchen night (step 6.3b: `.kitchen-night`, globals.css), and only there.

/**
 * The app's mark, hidden from readers (the name beside it, or the link around it, says it): the bakery's logo when the admin has set one
 * (Admin > System > Brand, lib/brand.ts), on a white tile with a hairline, else the app's own mark, a brand-red square with the line icon.
 */
export function AppMark({ size = "md" }: { size?: "md" | "lg" }) {
  const { logo } = useBrand();
  const box = size === "lg" ? "size-10 rounded-xl" : "size-9 rounded-[10px]";
  if (logo) {
    return (
      <span aria-hidden="true" className={`inline-flex shrink-0 overflow-hidden border border-neutral-200 bg-white ${box}`}>
        {/* a static export serves no image optimiser (next.config images.unoptimized) */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={logo} alt="" width={256} height={256} decoding="async" className="size-full object-contain" />
      </span>
    );
  }
  return (
    <span aria-hidden="true" className={`inline-flex shrink-0 items-center justify-center bg-brand-600 text-white ${box}`}>
      <Croissant className={size === "lg" ? "size-[22px]" : "size-5"} strokeWidth={1.8} aria-hidden="true" />
    </span>
  );
}

/** The business name (the bakery's when the admin has set it, else the app's own), for the sign-in screen and the sidebar. */
export function BrandName({ className = "" }: { className?: string }) {
  const { name } = useBrand();
  return <span className={className}>{name}</span>;
}

/**
 * The "Screens" navigation (its name and link names are what the earlier specs read, phase-6 plan 6.0.2): tabs under the station top bar
 * (the current screen underlined in brand red), or a row of plain links in the sign-in screen's footer.
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
// count in progress, positive while open.
const SHIFT_CHIP: Record<BarMode, { tone: Tone; key: string }> = {
  idle: { tone: "amber", key: "badge_none" },
  opening: { tone: "blue", key: "badge_opening" },
  open: { tone: "positive", key: "badge_open" },
  counting: { tone: "blue", key: "badge_counting" },
};

const SHIFT_DOT: Record<Tone, string> = {
  neutral: "bg-neutral-500",
  brand: "bg-brand-600",
  positive: "bg-positive-600",
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

/**
 * Whether this tablet's writes reached the server, in a word: online and sent, waiting (offline or not), or refused writes to look at.
 * `compact` (the station top bar only, step 6.3c): the dot and a short word ("Online", "3 waiting"), the whole sentence for readers, so the
 * screen's title never has to give way. The admin frame (AdminFrame) shows the whole sentence.
 */
export function SyncChip({ compact = false }: { compact?: boolean }) {
  const t = useTranslations("shell");
  const online = useOnline();
  const { here, elsewhere, conflicts, held } = useSyncState();
  const waiting = here + elsewhere;

  let tone: Tone = "positive";
  let text = t("sync_online");
  let short = t("sync_online_short");
  if (conflicts > 0) {
    tone = "amber";
    text = t("sync_conflicts", { count: conflicts });
    short = t("sync_conflicts_short", { count: conflicts });
  } else if (!online) {
    tone = "neutral";
    text = waiting > 0 ? t("sync_offline_waiting", { count: waiting }) : t("sync_offline");
    short = waiting > 0 ? t("sync_offline_waiting_short", { count: waiting }) : t("sync_offline");
  } else if (waiting > 0) {
    // a queue a device refusal holds (the tray says why) is amber, one that is only waiting its turn neutral
    tone = held ? "amber" : "neutral";
    text = t("sync_sending", { count: waiting });
    short = t("sync_sending_short", { count: waiting });
  }
  return (
    <Chip tone={tone} dot className="whitespace-nowrap">
      {compact ? (
        <>
          <span aria-hidden="true">{short}</span>
          <span className="sr-only">{text}</span>
        </>
      ) : (
        text
      )}
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
 * 6.0.2), the current one in brand red.
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
      {/* the person as the circle only (step 6.3c: the screen's title never gives way to the bar's right-hand side); readers hear whose
          menu it is ("Acting: <name>" while a waiter has the pad), and the name and role are the first lines of its panel */}
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        title={name}
        onClick={() => setOpen((now) => !now)}
        className={`flex items-center rounded-full p-1 ${night ? "hover:bg-night-divider" : "hover:bg-neutral-100"} ${focusRing}`}
      >
        <Avatar name={name} />
        <span className="sr-only">{waiter ? `${t("pad.acting_prefix")} ${name}` : name}</span>
      </button>
      {open ? (
        <div id={id} className={`${MENU_PANEL} w-72 space-y-3 p-4`}>
          <div className="leading-tight">
            <p className="truncate text-[15px] font-semibold">{name}</p>
            {roleText ? <p className="truncate text-[13px] text-neutral-600">{roleText}</p> : null}
            {waiter ? <p className="truncate text-[13px] text-neutral-600">{me.user.name}</p> : null}
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
            <BrandName className="sr-only" />
          </Link>
          <div className="flex min-w-0 flex-col">
            <p className="flex min-w-0 items-baseline gap-2">
              <span className={`shrink-0 whitespace-nowrap text-lg font-semibold leading-tight ${night ? "text-night-text" : "text-neutral-900"}`}>{title}</span>
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
          {signedIn ? <SyncChip compact /> : null}
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

/** The place for a screen's band under the bar (StationHeaderNotice): in the page's flow, so it pushes the page down; no height while it is empty. */
function StationNoticePlace() {
  const setNotice = useTopBarSlots((s) => s.setNotice);
  return <div ref={setNotice} />;
}

export function AppShell({ children }: { children: ReactNode }) {
  const t = useTranslations("app");
  const pathname = usePathname();
  useBrandTitle();
  const frame = frameOf(pathname);
  // the kitchen and bar displays are kitchen night from the top bar down (step 6.3b): the palette is remapped inside this frame only
  const night = isNightScreen(pathname);

  // `fill` (the waiter pad): from 1024 px the frame is the window's height, so the page's zones scroll on their own under the bar; below
  // it the page flows and scrolls as any other. Its main has no padding: the screen draws its zones edge to edge. A screen that is not
  // drawn yet there (the sign-in check, a refusal, the not-enrolled screen) keeps the padding of every other station page. The main never
  // gets shorter than 34rem: a tall band above it (the conflict tray) makes the page scroll instead of crushing the order panel.
  const fill = frame === "fill";
  return (
    <div className={`flex min-h-screen flex-col ${fill ? "lg:h-dvh lg:min-h-0" : ""} ${night ? "kitchen-night" : ""}`}>
      <a
        href="#content"
        className={`sr-only rounded-xl bg-white px-4 py-3 text-base font-semibold focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 ${focusRing}`}
      >
        {t("skip_to_content")}
      </a>
      {/* the admin console draws its own frame, the band included (AdminFrame) */}
      {frame === "login" || frame === "admin" ? null : (
        <>
          <StationBar fill={fill} />
          <StationNoticePlace />
        </>
      )}
      {frame === "admin" ? null : <PendingIndicator />}
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
