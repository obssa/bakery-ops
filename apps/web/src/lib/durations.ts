// How long something has waited, as the station screens show it (step 6.3c): the kitchen and bar tickets' timers, the till's waiting orders
// and the production board's batches in the oven. Under an hour it is minutes and seconds, "5:07"; under a day hours and minutes, "1 h 05";
// beyond that whole days, "3 d". So an order left open for days reads "4 d", not "5709:54". The words of the last two come from the
// dictionary (common.wait_hours, common.wait_days; lib/useWaitText.ts); this file only splits the time and stays free of React, so the
// logic spec runs it in Node.

export type Wait =
  | { unit: "clock"; minutes: number; seconds: number }
  | { unit: "hours"; hours: number; minutes: number }
  | { unit: "days"; days: number };

const HOUR = 3600;
const DAY = 86_400;

/** The wait split into its shown parts; a negative or fractional count of seconds is taken as the whole seconds from 0. */
export function waitOf(seconds: number): Wait {
  const s = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
  if (s < HOUR) return { unit: "clock", minutes: Math.floor(s / 60), seconds: s % 60 };
  if (s < DAY) return { unit: "hours", hours: Math.floor(s / HOUR), minutes: Math.floor((s % HOUR) / 60) };
  return { unit: "days", days: Math.floor(s / DAY) };
}

/** The words for the hours and the days, from the dictionary: "{hours} h {minutes}" (minutes two digits) and "{days} d". */
export interface WaitWords {
  hours: (values: { hours: number; minutes: string }) => string;
  days: (values: { days: number }) => string;
}

/** The wait as the screens print it: "0:42", "59:59", "1 h 05", "23 h 59", "1 d", "3 d". */
export function waitText(seconds: number, words: WaitWords): string {
  const wait = waitOf(seconds);
  if (wait.unit === "clock") return `${wait.minutes}:${String(wait.seconds).padStart(2, "0")}`;
  if (wait.unit === "hours") return words.hours({ hours: wait.hours, minutes: String(wait.minutes).padStart(2, "0") });
  return words.days({ days: wait.days });
}
