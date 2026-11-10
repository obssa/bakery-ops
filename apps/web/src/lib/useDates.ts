"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo } from "react";
import { create } from "zustand";
import { CLOCK_ALSO, clockText, hhmmText, type ClockAlso } from "./clock";
import { DEFAULT_DISPLAY, clockFormatOf, isoDayText, monthNames, readDisplay, writeDisplay, type DateDisplay, type DateFormat } from "./dates";
import { useSession } from "./session";

// The signed-in person's calendar (step 4.5f): Gregorian (the default), Ethiopian, or both, chosen in the shell's header and kept in this
// browser per user id (lib/dates.ts: every read and write guarded, so blocked storage reads Gregorian). Signed out, and on the first render
// of a static page (before GET /me answers), dates read Gregorian. useDateFormat() is what every date on screen goes through: the page's
// language, the person's calendar and the Ethiopian month names of messages/*.json. Every time of day reads on the Ethiopian clock
// (lib/clock.ts), with the international clock beside it when the admin turned it on (GET /me brand.clock_also, the same for everyone).

interface Choices {
  /** the choices made in this page's life, by user id (read from storage the first time they are asked for) */
  byUser: Record<number, DateDisplay>;
  choose: (userId: number, display: DateDisplay) => void;
}

function browserStorage(): Storage | null {
  // reading window.localStorage itself throws where site data is blocked: readDisplay and writeDisplay catch it
  return typeof window === "undefined" ? null : window.localStorage;
}

const useChoices = create<Choices>()((set) => ({
  byUser: {},
  choose: (userId, display) => {
    writeDisplay(browserStorage, userId, display);
    set((state) => ({ byUser: { ...state.byUser, [userId]: display } }));
  },
}));

const read = new Map<number, DateDisplay>();

function keptChoice(userId: number): DateDisplay {
  let display = read.get(userId);
  if (display === undefined) {
    display = readDisplay(browserStorage, userId);
    read.set(userId, display);
  }
  return display;
}

/** The person's calendar and the way to change it (a no-op while nobody is signed in). */
export function useDateDisplay(): [DateDisplay, (display: DateDisplay) => void] {
  const userId = useSession((s) => (s.status === "signed-in" ? (s.me?.user.id ?? null) : null));
  const chosen = useChoices((s) => (userId === null ? undefined : s.byUser[userId]));
  const choose = useChoices((s) => s.choose);
  const display = userId === null ? DEFAULT_DISPLAY : (chosen ?? keptChoice(userId));
  return [display, (next) => (userId === null ? undefined : choose(userId, next))];
}

/** The admin's international clock beside the Ethiopian one, from GET /me (none while signed out or before /me answers). */
export function useClockAlso(): ClockAlso {
  const also = useSession((s) => s.me?.brand?.clock_also);
  return also && (CLOCK_ALSO as readonly string[]).includes(also) ? also : "none";
}

/** How dates read on this page for this person: lib/dates.ts's DateFormat. */
export function useDateFormat(): DateFormat {
  const locale = useLocale();
  const t = useTranslations("dates");
  const [display] = useDateDisplay();
  const clockAlso = useClockAlso();
  return useMemo(() => {
    const names: Record<string, string> = {};
    for (let month = 1; month <= 13; month++) names[String(month)] = t(`ethiopian_months.${month}`);
    return { locale, display, months: monthNames(names), clockAlso };
  }, [locale, display, t, clockAlso]);
}

/** An instant's time of day as this page writes it: "1:50 ጠዋት", "1:50 morning · 7:50 AM" ("" for none). */
export function useClock(): (iso: string | Date | null | undefined, options?: { seconds?: boolean }) => string {
  const fmt = clockFormatOf(useDateFormat());
  return (iso, options) => clockText(iso, fmt, options);
}

/** A template's "HH:MM" (24-hour) as this page writes it: "12:00 ጠዋት" for 06:00. */
export function useHhmm(): (hhmm: string | null | undefined) => string {
  const fmt = clockFormatOf(useDateFormat());
  return (hhmm) => hhmmText(hhmm, fmt);
}

/** A local date as the shift pages and the order labels write it ("2026-10-27", or in the person's calendar). */
export function useIsoDay(): (date: string) => string {
  const fmt = useDateFormat();
  return (date) => isoDayText(date, fmt);
}
