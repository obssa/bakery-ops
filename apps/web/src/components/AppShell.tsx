"use client";

import { Croissant, LogOut } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { Suspense, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import type { Locale } from "@/lib/api/types";
import { frameOf, isCurrent, isNightScreen, SCREENS } from "@/lib/screens";
import { useSession } from "@/lib/session";
import { barMode, type BarMode } from "@/lib/shifts";
import { useStationChrome } from "@/lib/station";
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

// The frame around every page (step 6.2b, fresh-counter.md 3): the 68 px station top bar, then the "Screens" navigation, the outbox's band
// and the page. The bar holds, on the left, the app's mark (the way home) and, on a station screen, the station's name and a chip with its
// shift; on the right, in this order, the sync chip, the person's calendar (step 4.5f), the language button (the other language's name),
// the bell and the person (initials, name and role) with "Sign out". The "Screens" navigation keeps its name and its link names, visible
// under the bar: at 1280 px there is no room for it beside the bar's own contents in either language. The admin console draws its sidebar
// frame and its own `main` (AdminFrame); the sign-in screen is a split of its own, without the bar, and carries "Screens" itself. A page
// whose calls are refused because this device is not enrolled shows the "not enrolled" screen instead (step 5.1b, DeviceGate).

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

// The shift chip's tone and word in each state of the shift bar (the shift bar's own badge, ShiftBar.tsx): amber while none runs, blue for a
// count in progress, brand teal while open.
const SHIFT_CHIP: Record<BarMode, { tone: Tone; key: string }> = {
  idle: { tone: "amber", key: "badge_none" },
  opening: { tone: "blue", key: "badge_opening" },
  open: { tone: "brand", key: "badge_open" },
  counting: { tone: "blue", key: "badge_counting" },
};

/** The station the screen works at and its shift, as the screen's shift feed read it (lib/station.ts useStationChrome). */
function StationContext() {
  const t = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const isoDay = useIsoDay();
  const chrome = useStationChrome((s) => s.chrome);
  if (!chrome) return null;

  const { station, shift } = chrome;
  let chip: { tone: Tone; text: string } | null = null;
  if (shift !== undefined) {
    const { tone, key } = SHIFT_CHIP[barMode(shift)];
    const label = shift
      ? t("shift_label", { name: shift.template ? (locale === "am" ? shift.template.name_am : shift.template.name_en) : t("ad_hoc"), date: isoDay(shift.date) })
      : null;
    chip = { tone, text: label ? `${t(key)} · ${label}` : t(key) };
  }

  return (
    <>
      <span className="min-w-0 truncate text-lg font-semibold text-neutral-900">{locale === "am" ? station.name_am : station.name_en}</span>
      {chip ? (
        <Chip tone={chip.tone} dot className="min-w-0 shrink">
          <span className="min-w-0 truncate">{chip.text}</span>
        </Chip>
      ) : null}
    </>
  );
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

/** The person signed in on this device: initials, name and role (the name and role from 1280 px), and "Sign out"; or "Sign in". */
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

function TopBar() {
  const t = useTranslations("app");
  const signedIn = useSession((s) => s.status === "signed-in");
  const atStation = useStationChrome((s) => s.chrome !== null);

  return (
    <header className="border-b border-neutral-200 bg-white">
      <div className="flex min-h-17 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6 md:h-17 md:flex-nowrap md:py-0">
        <div className="flex min-w-0 flex-1 items-center gap-3.5">
          <Link href="/" className={`flex shrink-0 items-center gap-3 rounded-xl ${focusRing}`}>
            <AppMark />
            {/* on a station screen the station's name takes the place; the link is still named after the app */}
            <span className={atStation ? "sr-only" : "text-lg font-bold tracking-tight text-neutral-900"}>{t("name")}</span>
          </Link>
          <StationContext />
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
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
  const pathname = usePathname();
  const frame = frameOf(pathname);
  // the kitchen and bar displays are kitchen night from the top bar down (step 6.3b): the palette is remapped inside this frame only
  const night = isNightScreen(pathname);

  return (
    <div className={`flex min-h-screen flex-col ${night ? "kitchen-night" : ""}`}>
      <a
        href="#content"
        className={`sr-only rounded-xl bg-white px-4 py-3 text-base font-semibold focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 ${focusRing}`}
      >
        {t("skip_to_content")}
      </a>
      {frame === "login" ? null : <TopBar />}
      <PendingIndicator />
      {frame === "admin" ? (
        children
      ) : (
        <main id="content" className={frame === "login" ? "flex flex-1 flex-col" : "w-full flex-1 px-4 py-6 sm:px-6"}>
          <DeviceGate>{children}</DeviceGate>
        </main>
      )}
    </div>
  );
}
