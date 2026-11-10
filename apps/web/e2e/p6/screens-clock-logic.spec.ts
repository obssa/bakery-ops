import { expect, test } from "@playwright/test";
import { clockText, DAY_ORDER, DAY_PARTS, ethiopianOf, hhmmText, hour24Of, hourTick, internationalText, partOf, wallText, type DayPart } from "../../src/lib/clock";

// The Ethiopian clock (the Ethiopian clock follow-up, Ramin 2026-11-10; lib/clock.ts): the day's hours start at sunrise, so 06:00 is 12 in
// the morning and 07:00 is 1. Four parts: morning (ጠዋት) 06:00-11:59, afternoon (ከሰዓት) 12:00-17:59, evening (ማታ) 18:00-23:59, night
// (ሌሊት) 00:00-05:59. The API prints the same words (tests/Unit/Shared/EthiopianClockTest.php). Plain functions, no page: it runs in
// p6-a11y beside the screenshots.

const pad = (n: number) => String(n).padStart(2, "0");

test.describe("the Ethiopian clock", () => {
  test("every minute of the day has an hour of 1 to 12, the right part, and turns back into the same 24-hour time", () => {
    const seen = new Map<DayPart, Set<number>>(DAY_PARTS.map((part) => [part, new Set<number>()]));
    for (let h = 0; h < 24; h++) {
      for (let m = 0; m < 60; m++) {
        const { hour, minute, part } = ethiopianOf(h, m);
        expect(hour, `${pad(h)}:${pad(m)}`).toBeGreaterThanOrEqual(1);
        expect(hour, `${pad(h)}:${pad(m)}`).toBeLessThanOrEqual(12);
        expect(minute).toBe(m);
        expect(part).toBe(h < 6 ? "night" : h < 12 ? "morning" : h < 18 ? "afternoon" : "evening");
        expect(hour, `${pad(h)}:${pad(m)}`).toBe((h + 6) % 12 || 12);
        expect(hour24Of(hour, part), `${pad(h)}:${pad(m)} back`).toBe(h);
        seen.get(part)!.add(hour);
      }
    }
    // morning and evening have 12 and 1 to 5, afternoon and night 6 to 11: twelve hours of the day, twelve of the night
    expect([...seen.get("morning")!].sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5, 12]);
    expect([...seen.get("afternoon")!].sort((a, b) => a - b)).toEqual([6, 7, 8, 9, 10, 11]);
    expect([...seen.get("evening")!].sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5, 12]);
    expect([...seen.get("night")!].sort((a, b) => a - b)).toEqual([6, 7, 8, 9, 10, 11]);
    // the picker's list: the day from sunrise
    expect(DAY_ORDER.slice(0, 3)).toEqual([6, 7, 8]);
    expect(DAY_ORDER.slice(-2)).toEqual([4, 5]);
  });

  test("the boundaries of the parts of the day", () => {
    const cases: [string, string, string][] = [
      ["00:00", "6:00 night", "6:00 ሌሊት"],
      ["05:59", "11:59 night", "11:59 ሌሊት"],
      ["06:00", "12:00 morning", "12:00 ጠዋት"],
      ["07:00", "1:00 morning", "1:00 ጠዋት"],
      ["07:50", "1:50 morning", "1:50 ጠዋት"],
      ["11:59", "5:59 morning", "5:59 ጠዋት"],
      ["12:00", "6:00 afternoon", "6:00 ከሰዓት"],
      ["16:10", "10:10 afternoon", "10:10 ከሰዓት"],
      ["17:59", "11:59 afternoon", "11:59 ከሰዓት"],
      ["18:00", "12:00 evening", "12:00 ማታ"],
      ["23:05", "5:05 evening", "5:05 ማታ"],
      ["23:59", "5:59 evening", "5:59 ማታ"],
    ];
    for (const [hhmm, en, am] of cases) {
      expect(hhmmText(hhmm, { locale: "en" }), hhmm).toBe(en);
      expect(hhmmText(hhmm, { locale: "am" }), hhmm).toBe(am);
    }
    expect(partOf(5)).toBe("night");
    expect(partOf(6)).toBe("morning");
    expect(partOf(12)).toBe("afternoon");
    expect(partOf(18)).toBe("evening");
  });

  test("the international clock beside it, when the admin turned it on", () => {
    expect(wallText(16, 10, { locale: "en", also: "h12" })).toBe("10:10 afternoon · 4:10 PM");
    expect(wallText(16, 10, { locale: "am", also: "h24" })).toBe("10:10 ከሰዓት · 16:10");
    expect(wallText(16, 10, { locale: "am", also: "none" })).toBe("10:10 ከሰዓት");
    expect(wallText(0, 15, { locale: "en", also: "h12" })).toBe("6:15 night · 12:15 AM");
    expect(wallText(12, 0, { locale: "en", also: "h12" })).toBe("6:00 afternoon · 12:00 PM");
    expect(wallText(7, 5, { locale: "en", also: "h24" })).toBe("1:05 morning · 07:05");
    expect(internationalText(23, 59, "h12")).toBe("11:59 PM");
    expect(internationalText(23, 59, "none")).toBeNull();
    // the order timeline's seconds
    expect(wallText(16, 10, { locale: "en", also: "h24" }, 5)).toBe("10:10:05 afternoon · 16:10:05");
  });

  test("an instant reads on the bakery's wall clock (Addis Ababa, UTC+3), whatever the device's zone", () => {
    expect(clockText("2026-11-10T04:50:00Z", { locale: "am" })).toBe("1:50 ጠዋት");
    expect(clockText("2026-11-10T13:10:00Z", { locale: "en" })).toBe("10:10 afternoon");
    expect(clockText("2026-11-10T21:15:00Z", { locale: "en" })).toBe("6:15 night");
    expect(clockText("2026-11-10T16:10:00+03:00", { locale: "en" })).toBe("10:10 afternoon");
    expect(clockText(null, { locale: "en" })).toBe("");
    expect(clockText("not a time", { locale: "en" })).toBe("not a time");
    expect(hhmmText("25:00", { locale: "en" })).toBe("25:00");
  });

  test("a chart's ticks: the hour, with the part of the day where a part starts", () => {
    expect(hourTick(6, "en")).toBe("12\nmorning");
    expect(hourTick(7, "en", 6)).toBe("1");
    expect(hourTick(12, "am", 11)).toBe("6\nከሰዓት");
    expect(hourTick(17, "am", 16)).toBe("11");
    expect(hourTick(18, "en", 17)).toBe("12\nevening");
    expect(hourTick(9, "am")).toBe("3\nጠዋት");
  });
});
