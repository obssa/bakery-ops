import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { waitOf, waitText, type WaitWords } from "../../src/lib/durations";

// The waits the station screens print (step 6.3c, lib/durations.ts): the kitchen and bar timers, the till's waiting orders and the
// production board's batches in the oven. m:ss under an hour, hours and minutes under a day, whole days beyond, so an order left open for
// days reads "4 d" and never "5709:54". Plain functions, no page: it runs in p6-a11y beside the screenshots of those screens.

/** The dictionary's words, filled as next-intl fills them. */
function words(messages: typeof en): WaitWords {
  const fill = (text: string, values: Record<string, string | number>) => text.replace(/\{(\w+)\}/g, (whole, key: string) => String(values[key] ?? whole));
  return { hours: (values) => fill(messages.common.wait_hours, values), days: (values) => fill(messages.common.wait_days, values) };
}

test.describe("waits on the station screens", () => {
  test("under an hour: minutes and seconds, the seconds always two digits", () => {
    expect(waitText(0, words(en))).toBe("0:00");
    expect(waitText(42, words(en))).toBe("0:42");
    expect(waitText(75, words(en))).toBe("1:15");
    expect(waitText(307, words(en))).toBe("5:07");
    expect(waitText(3599, words(en))).toBe("59:59");
  });

  test("from an hour to a day: hours and minutes, the minutes always two digits", () => {
    expect(waitText(3600, words(en))).toBe("1 h 00");
    expect(waitText(3900, words(en))).toBe("1 h 05");
    expect(waitText(3959, words(en))).toBe("1 h 05");
    expect(waitText(86_399, words(en))).toBe("23 h 59");
  });

  test("a day and more: whole days", () => {
    expect(waitText(86_400, words(en))).toBe("1 d");
    expect(waitText(3 * 86_400 + 7200, words(en))).toBe("3 d");
    // the stale order the review saw as "5709:54": 342 594 s is 3 d 23 h 9 min
    expect(waitText(5709 * 60 + 54, words(en))).toBe("3 d");
  });

  test("a clock that runs behind, a fraction or no number at all reads as no wait", () => {
    expect(waitOf(-12)).toEqual({ unit: "clock", minutes: 0, seconds: 0 });
    expect(waitOf(61.9)).toEqual({ unit: "clock", minutes: 1, seconds: 1 });
    expect(waitOf(Number.NaN)).toEqual({ unit: "clock", minutes: 0, seconds: 0 });
  });

  test("the words are the dictionary's, in both languages", () => {
    expect(waitText(3900, words(am))).toBe(am.common.wait_hours.replace("{hours}", "1").replace("{minutes}", "05"));
    expect(waitText(2 * 86_400, words(am))).toBe(am.common.wait_days.replace("{days}", "2"));
    expect(waitText(307, words(am))).toBe("5:07");
  });
});
