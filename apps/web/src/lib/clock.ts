// The Ethiopian clock (Phase 6 follow-up, Ramin 2026-11-10): every time of day the app shows reads the way people in Ethiopia say it. The
// day's twelve hours start at sunrise, so 06:00 is 12 o'clock in the morning and 07:00 is 1; 18:00 is 12 again and the night's hours run 1
// to 12 until the next morning. The hour is ((h + 6) mod 12), 12 for 0, and the part of the day says which twelve:
//
//   morning   ጠዋት   06:00-11:59  (12:00 to 5:59)        afternoon  ከሰዓት  12:00-17:59  (6:00 to 11:59)
//   evening   ማታ    18:00-23:59  (12:00 to 5:59)        night      ሌሊት   00:00-05:59  (6:00 to 11:59)
//
// 07:50 is "1:50 ጠዋት" ("1:50 morning"), 16:10 "10:10 ከሰዓት", 00:15 "6:15 ሌሊት". The admin can show the international clock beside it
// (Admin > System > Time, GET /me brand.clock_also): "10:10 ከሰዓት · 4:10 PM" (h12) or "10:10 ከሰዓት · 16:10" (h24). Always the bakery's
// wall clock (Africa/Addis_Ababa, UTC+3 all year), whatever the device's zone. A label only: every time sent to the API, every template
// time and every export stays the 24-hour clock. The API prints the same words (app/Shared/Time/EthiopianClock.php).

export type DayPart = "morning" | "afternoon" | "evening" | "night";
export const DAY_PARTS: readonly DayPart[] = ["morning", "afternoon", "evening", "night"];

/** The international clock shown beside the Ethiopian one: none (the default), 12-hour or 24-hour. */
export type ClockAlso = "none" | "h12" | "h24";
export const CLOCK_ALSO: readonly ClockAlso[] = ["none", "h12", "h24"];

/** The words of the parts of the day; the API's EthiopianClock::PARTS_EN and PARTS_AM say the same. */
export const PART_WORDS: Readonly<Record<"en" | "am", Readonly<Record<DayPart, string>>>> = {
  en: { morning: "morning", afternoon: "afternoon", evening: "evening", night: "night" },
  am: { morning: "ጠዋት", afternoon: "ከሰዓት", evening: "ማታ", night: "ሌሊት" },
};

/** How a time reads for this page: its language and the admin's international clock. */
export interface ClockFormat {
  locale: string;
  also?: ClockAlso;
}

export interface EthiopianTime {
  /** 1 to 12 */
  hour: number;
  minute: number;
  part: DayPart;
}

const ADDIS_OFFSET_MS = 3 * 60 * 60 * 1000; // Africa/Addis_Ababa is UTC+3 all year (no daylight saving)

/** The part of the day a 24-hour hour falls in. */
export function partOf(hour24: number): DayPart {
  if (hour24 < 6) return "night";
  if (hour24 < 12) return "morning";
  if (hour24 < 18) return "afternoon";
  return "evening";
}

/** A 24-hour time of day on the Ethiopian clock: 16:10 is 10:10 in the afternoon. */
export function ethiopianOf(hour24: number, minute: number): EthiopianTime {
  return { hour: (hour24 + 6) % 12 || 12, minute, part: partOf(hour24) };
}

/**
 * The other way: 12 in the morning is 06:00, 6 in the afternoon 12:00, 12 in the evening 18:00, 6 at night 00:00. The 24-hour hour, 0 to
 * 23. Each part has six of the twelve hours (morning and evening 12 and 1 to 5, afternoon and night 6 to 11); another hour reads as the
 * same hour of the day half (1 in the afternoon is 07:00).
 */
export function hour24Of(hour: number, part: DayPart): number {
  const night = part === "evening" || part === "night" ? 12 : 0;
  return ((hour % 12) + 6 + night) % 24;
}

/** The 24 hours of the day in the Ethiopian day's order, from sunrise: 06:00 (12 in the morning) first, 05:00 (11 at night) last. */
export const DAY_ORDER: readonly number[] = Array.from({ length: 24 }, (_, i) => (i + 6) % 24);

/** The bakery's wall clock of an instant: [hour 0-23, minute, second]; null for a text that is no time. */
export function addisWall(iso: string | Date | null | undefined): [number, number, number] | null {
  if (iso === null || iso === undefined || iso === "") return null;
  const time = iso instanceof Date ? iso.getTime() : Date.parse(iso);
  if (Number.isNaN(time)) return null;
  const local = new Date(time + ADDIS_OFFSET_MS);
  return [local.getUTCHours(), local.getUTCMinutes(), local.getUTCSeconds()];
}

function words(locale: string): Readonly<Record<DayPart, string>> {
  return locale === "am" ? PART_WORDS.am : PART_WORDS.en;
}

const pad = (n: number) => String(n).padStart(2, "0");

/** "4:10 PM" (h12), "16:10" (h24), or null; with the seconds when asked. */
export function internationalText(hour24: number, minute: number, also: ClockAlso | undefined, second?: number): string | null {
  const tail = second === undefined ? "" : `:${pad(second)}`;
  if (also === "h12") return `${hour24 % 12 || 12}:${pad(minute)}${tail} ${hour24 < 12 ? "AM" : "PM"}`;
  if (also === "h24") return `${pad(hour24)}:${pad(minute)}${tail}`;
  return null;
}

/** A 24-hour time of day for people: "10:10 ከሰዓት", "10:10 afternoon · 4:10 PM"; with the seconds when asked ("10:10:05 afternoon"). */
export function wallText(hour24: number, minute: number, fmt: ClockFormat, second?: number): string {
  const { hour, part } = ethiopianOf(hour24, minute);
  const own = `${hour}:${pad(minute)}${second === undefined ? "" : `:${pad(second)}`} ${words(fmt.locale)[part]}`;
  const international = internationalText(hour24, minute, fmt.also, second);
  return international === null ? own : `${own} · ${international}`;
}

/** An instant (ISO 8601) on the bakery's clock: "1:50 ጠዋት"; "" for none, the text itself for one that is no time. */
export function clockText(iso: string | Date | null | undefined, fmt: ClockFormat, options: { seconds?: boolean } = {}): string {
  if (iso === null || iso === undefined || iso === "") return "";
  const wall = addisWall(iso);
  if (!wall) return typeof iso === "string" ? iso : "";
  return wallText(wall[0], wall[1], fmt, options.seconds ? wall[2] : undefined);
}

/** A template's or a roster's "HH:MM" (24-hour) for people: "12:00 ጠዋት" for 06:00; a text that is no time is kept. */
export function hhmmText(hhmm: string | null | undefined, fmt: ClockFormat): string {
  const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(hhmm ?? "");
  if (!match) return hhmm ?? "";
  const hour24 = Number(match[1]);
  const minute = Number(match[2]);
  if (hour24 > 23 || minute > 59) return hhmm ?? "";
  return wallText(hour24, minute, fmt);
}

/**
 * An hour of the day as a chart's tick, short enough for a column: the Ethiopian hour ("1" for 07:00), and on a second line the part of the
 * day where a part starts (the first tick, or the hour before was in another part): "12\nጠዋት" for 06:00, then "1", "2", ... "6\nከሰዓት".
 */
export function hourTick(hour24: number, locale: string, previous?: number): string {
  const { hour, part } = ethiopianOf(hour24, 0);
  return previous === undefined || partOf(previous) !== part ? `${hour}\n${words(locale)[part]}` : String(hour);
}
