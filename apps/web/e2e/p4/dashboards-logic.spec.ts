import { expect, test } from "@playwright/test";
import { filterParams, filtersFromParams } from "../../src/lib/adminPayments";
import {
  addDays,
  agoOf,
  analyticsKeys,
  changedDays,
  dayLabel,
  daysBetween,
  differenceOf,
  exportFileName,
  freshnessRange,
  groupDigits,
  marginOf,
  paymentsDrill,
  percentChange,
  prepTime,
  presetRange,
  previousPeriod,
  queryTouches,
  rangeFromParams,
  rangeIssue,
  rangeParams,
  rebuildPlan,
  seriesKeyOf,
  seriesLabel,
  spanOf,
  weekStart,
  type FreshnessDay,
} from "../../src/lib/analytics";
import { barsLayout, extentOf, lineSegments, pathOf } from "../../src/lib/charts";
import { resolveDeepLink } from "../../src/lib/deeplink";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7), step 4.5e: the analytics screens' rules as plain logic (no
// browser, no API). The range maths in the branch's local dates (the presets, the previous period, Monday weeks, the API's 400-day and
// "not after today" checks, the address), the freshness feed's version changes and the queries they invalidate, the Rebuild button's
// requests, the change from the previous period on decimal strings, the payments-list drills, and the charts' scaling: decimal strings
// become numbers only for pixel positions, never for a figure that is shown. The Ethiopian date labels are step 4.5f's.

const params = (query: Record<string, string>) => new URLSearchParams(query);
const TODAY = "2026-10-27"; // a Tuesday

function day(date: string, version: number, rebuiltAt = `${date}T21:05:00Z`): FreshnessDay {
  return { day: date, version, rebuilt_at: rebuiltAt, trigger: "nightly", changed: true };
}

test.describe("dashboards-logic", () => {
  test("the presets are ranges of local days that end today: today, 7, 30 and 90 days", () => {
    expect(presetRange("today", TODAY)).toEqual({ preset: "today", from: "2026-10-27", to: "2026-10-27" });
    expect(presetRange("7", TODAY)).toEqual({ preset: "7", from: "2026-10-21", to: "2026-10-27" });
    expect(presetRange("30", TODAY)).toEqual({ preset: "30", from: "2026-09-28", to: "2026-10-27" });
    expect(presetRange("90", TODAY)).toEqual({ preset: "90", from: "2026-07-30", to: "2026-10-27" });
    // across a month end and a leap day, both ends counted
    expect(presetRange("7", "2028-03-02")).toEqual({ preset: "7", from: "2028-02-25", to: "2028-03-02" });
    expect(daysBetween("2028-02-25", "2028-03-02")).toBe(7);
    expect(daysBetween(TODAY, TODAY)).toBe(1);
    expect(addDays("2026-12-31", 1)).toBe("2027-01-01");
    expect(addDays(TODAY, -399)).toBe("2025-09-23");
  });

  test("the previous period is the same number of local days just before the range", () => {
    expect(previousPeriod({ from: "2026-03-01", to: "2026-03-31" })).toEqual({ from: "2026-01-29", to: "2026-02-28" });
    expect(previousPeriod({ from: TODAY, to: TODAY })).toEqual({ from: "2026-10-26", to: "2026-10-26" });
    expect(spanOf({ from: "2026-10-21", to: TODAY }, true)).toEqual({ from: "2026-10-14", to: TODAY });
    expect(spanOf({ from: "2026-10-21", to: TODAY }, false)).toEqual({ from: "2026-10-21", to: TODAY });
    // the freshness feed reads at most 400 days, as every /analytics read: a compared 400-day range is watched over its own days
    expect(freshnessRange({ from: "2026-10-21", to: TODAY }, true)).toEqual({ from: "2026-10-14", to: TODAY });
    expect(freshnessRange({ from: addDays(TODAY, -299), to: TODAY }, true)).toEqual({ from: addDays(TODAY, -299), to: TODAY });
  });

  test("a custom range is checked as the API checks it: real dates, from not after to, never after today, at most 400 days", () => {
    expect(rangeIssue("2026-10-01", TODAY, TODAY)).toBeNull();
    expect(rangeIssue("", TODAY, TODAY)).toBe("dates");
    expect(rangeIssue("2026-02-30", "2026-03-01", TODAY)).toBe("dates");
    expect(rangeIssue("2026-10-20", "2026-10-10", TODAY)).toBe("order");
    expect(rangeIssue("2026-10-20", "2026-10-28", TODAY)).toBe("future");
    expect(rangeIssue(addDays(TODAY, -399), TODAY, TODAY)).toBeNull();
    expect(rangeIssue(addDays(TODAY, -400), TODAY, TODAY)).toBe("too_large");
  });

  test("the range lives in the page's address: a preset by name, a custom range by its dates, anything malformed dropped", () => {
    expect(rangeFromParams(params({}), TODAY)).toEqual({ preset: "30", from: "2026-09-28", to: TODAY });
    expect(rangeFromParams(params({ range: "7" }), TODAY)).toEqual({ preset: "7", from: "2026-10-21", to: TODAY });
    expect(rangeFromParams(params({ range: "today" }), TODAY)).toEqual({ preset: "today", from: TODAY, to: TODAY });
    expect(rangeFromParams(params({ range: "12" }), TODAY)).toEqual({ preset: "30", from: "2026-09-28", to: TODAY });
    expect(rangeFromParams(params({ from: "2026-10-19", to: "2026-10-25" }), TODAY)).toEqual({ preset: "custom", from: "2026-10-19", to: "2026-10-25" });
    // dates win over a preset; a missing `to` is today, as the API reads it
    expect(rangeFromParams(params({ range: "7", from: "2026-10-01", to: "2026-10-05" }), TODAY)).toEqual({ preset: "custom", from: "2026-10-01", to: "2026-10-05" });
    expect(rangeFromParams(params({ from: "2026-10-19" }), TODAY)).toEqual({ preset: "custom", from: "2026-10-19", to: TODAY });
    expect(rangeFromParams(params({ from: "2026-13-01", to: "2026-10-05" }), TODAY)).toEqual({ preset: "30", from: "2026-09-28", to: TODAY });
    // a range past today is kept as it was asked for: the API says why it cannot read it (analytics.range.future, in both languages)
    expect(rangeFromParams(params({ from: "2026-10-20", to: "2026-10-30" }), TODAY)).toEqual({ preset: "custom", from: "2026-10-20", to: "2026-10-30" });

    // and back: the default range leaves the address clean
    expect(rangeParams(presetRange("30", TODAY))).toEqual({});
    expect(rangeParams(presetRange("today", TODAY))).toEqual({ range: "today" });
    expect(rangeParams({ preset: "custom", from: "2026-10-19", to: "2026-10-25" })).toEqual({ from: "2026-10-19", to: "2026-10-25" });
    for (const range of [presetRange("7", TODAY), presetRange("90", TODAY), { preset: "custom" as const, from: "2026-01-01", to: "2026-03-31" }]) {
      expect(rangeFromParams(params(rangeParams(range)), TODAY)).toEqual(range);
    }
  });

  test("the weekly summary's inbox line opens the dashboards on its week; the product page is a page of the web", () => {
    const link = resolveDeepLink("/admin/analytics?from=2026-10-19&to=2026-10-25");
    expect(link).toEqual({ pathname: "/admin/analytics", query: { from: "2026-10-19", to: "2026-10-25" } });
    expect(rangeFromParams(params(link?.query ?? {}), TODAY)).toEqual({ preset: "custom", from: "2026-10-19", to: "2026-10-25" });
    for (const page of ["sales", "channels", "products", "stations", "staff", "shifts", "me"]) {
      expect(resolveDeepLink(`/admin/analytics/${page}`)?.pathname).toBe(`/admin/analytics/${page}`);
    }
    expect(resolveDeepLink("/admin/analytics/product?id=12")).toEqual({ pathname: "/admin/analytics/product", query: { id: "12" } });
  });

  test("weeks start on Monday, local; months are YYYY-MM; a series key is labelled in one place", () => {
    expect(weekStart(TODAY)).toBe("2026-10-26");
    expect(weekStart("2026-10-26")).toBe("2026-10-26");
    expect(weekStart("2026-11-01")).toBe("2026-10-26"); // a Sunday ends the week
    expect(weekStart("2026-01-01")).toBe("2025-12-29"); // across a year end
    expect(seriesKeyOf(TODAY, "day")).toBe(TODAY);
    expect(seriesKeyOf(TODAY, "week")).toBe("2026-10-26");
    expect(seriesKeyOf(TODAY, "month")).toBe("2026-10");
    expect(dayLabel(TODAY, "en")).toBe("27 Oct");
    expect(dayLabel(TODAY, "en", true)).toBe("27 Oct 2026");
    expect(seriesLabel(TODAY, "day", "en")).toBe("Tue 27 Oct");
    expect(seriesLabel("2026-10-26", "week", "en")).toBe("26 Oct");
    expect(seriesLabel("2026-10", "month", "en")).toBe("Oct 2026");
    // Amharic labels come from the same function, in Amharic
    expect(dayLabel(TODAY, "am")).not.toBe(dayLabel(TODAY, "en"));
    expect(seriesLabel("2026-10", "month", "am")).toContain("2026");
  });

  test("the freshness badge says how long ago the figures were rebuilt", () => {
    const now = Date.parse("2026-10-27T10:00:00Z");
    expect(agoOf(null, now)).toEqual({ unit: "never", count: 0 });
    expect(agoOf("2026-10-27T09:59:30Z", now)).toEqual({ unit: "now", count: 0 });
    expect(agoOf("2026-10-27T10:00:05Z", now)).toEqual({ unit: "now", count: 0 }); // a device clock a little behind the server's
    expect(agoOf("2026-10-27T09:58:00Z", now)).toEqual({ unit: "minutes", count: 2 });
    expect(agoOf("2026-10-27T09:00:01Z", now)).toEqual({ unit: "minutes", count: 59 });
    expect(agoOf("2026-10-27T07:00:00Z", now)).toEqual({ unit: "hours", count: 3 });
    expect(agoOf("2026-10-25T09:00:00Z", now)).toEqual({ unit: "days", count: 2 });
  });

  test("a moved version invalidates the queries of the ranges that hold the day, and only those; one query key per answer shape", () => {
    const before = [day("2026-10-26", 3), day(TODAY, 5)];
    expect(changedDays(null, before)).toEqual([]); // the first look is the baseline
    expect(changedDays(before, before)).toEqual([]);
    expect(changedDays(before, [day("2026-10-25", 1), day("2026-10-26", 3), day(TODAY, 6)])).toEqual(["2026-10-25", TODAY]);
    // a day deleted and built again starts at version 1: its rebuilt_at still moves
    expect(changedDays([day(TODAY, 1, "2026-10-27T08:00:00Z")], [day(TODAY, 1, "2026-10-27T09:00:00Z")])).toEqual([TODAY]);
    expect(changedDays(before, [day(TODAY, 5)])).toEqual(["2026-10-26"]);

    const week = { from: "2026-10-21", to: TODAY };
    expect(queryTouches(analyticsKeys.overview(week, false), [TODAY])).toBe(true);
    expect(queryTouches(analyticsKeys.overview({ from: "2026-10-21", to: "2026-10-26" }, false), [TODAY])).toBe(false);
    expect(queryTouches(analyticsKeys.overview(week, false), ["2026-10-15"])).toBe(false);
    expect(queryTouches(analyticsKeys.overview(week, true), ["2026-10-15"])).toBe(true); // the previous period is read too
    expect(queryTouches(analyticsKeys.channels(week, "week"), ["2026-10-21"])).toBe(true);
    expect(queryTouches(analyticsKeys.item(week, 12), ["2026-08-01"])).toBe(true); // the product page follows its weeks since launch
    expect(queryTouches(["payment-channels", "all"], [TODAY])).toBe(false);

    const keys = [
      analyticsKeys.overview(week, false),
      analyticsKeys.sales(week, "day"),
      analyticsKeys.hourly(week),
      analyticsKeys.channels(week, "day"),
      analyticsKeys.items(week, { kind: "", categoryId: "", sort: "revenue" }),
      analyticsKeys.item(week, 12),
      analyticsKeys.stations(week),
      analyticsKeys.staff(week, ""),
      analyticsKeys.shifts(week),
    ];
    expect(new Set(keys.map((key) => key[1])).size).toBe(keys.length);
    expect(keys.every((key) => key[0] === "analytics")).toBe(true);
  });

  test("the Rebuild button asks for the days not built: newest first, at most 31 days a request, inline (sync) only for 7 days or fewer", () => {
    expect(rebuildPlan([])).toEqual([]);
    expect(rebuildPlan([TODAY])).toEqual([{ from: TODAY, to: TODAY, sync: true }]);
    // days close together go in one request (a built day among them is rebuilt to the same figures: the rebuild is idempotent)
    expect(rebuildPlan(["2026-10-22", "2026-10-20", "2026-10-25", "2026-10-21", "2026-10-21"])).toEqual([{ from: "2026-10-20", to: "2026-10-25", sync: true }]);
    const run = (from: string, days: number) => Array.from({ length: days }, (_, i) => addDays(from, i));
    expect(rebuildPlan(run("2026-10-20", 8))).toEqual([{ from: "2026-10-20", to: TODAY, sync: false }]);
    expect(rebuildPlan(run("2026-07-30", 90))).toEqual([
      { from: addDays(TODAY, -30), to: TODAY, sync: false },
      { from: addDays(TODAY, -61), to: addDays(TODAY, -31), sync: false },
      { from: "2026-07-30", to: addDays(TODAY, -62), sync: false },
    ]);
    expect(rebuildPlan(["2026-06-01", TODAY])).toEqual([
      { from: TODAY, to: TODAY, sync: true },
      { from: "2026-06-01", to: "2026-06-01", sync: true },
    ]);
  });

  test("the change from the previous period is worked on decimal strings: a signed percent with one decimal, none when there was nothing before", () => {
    expect(percentChange("1100.00", "1000.00")).toBe("+10.0");
    expect(percentChange("900.00", "1000.00")).toBe("-10.0");
    expect(percentChange("1000.00", "1000.00")).toBe("0.0");
    expect(percentChange("0.00", "1000.00")).toBe("-100.0");
    expect(percentChange("5.00", "0.00")).toBeNull();
    expect(percentChange("0.30", "0.10")).toBe("+200.0");
    expect(percentChange("2", "3")).toBe("-33.3"); // counts too, as text
    expect(percentChange("5", "6")).toBe("-16.7"); // half up
    expect(percentChange("12345678901.23", "12345678901.22")).toBe("0.0");
    expect(differenceOf("1100.00", "1000.00")).toBe("+100.00");
    expect(differenceOf("900.00", "1000.00")).toBe("-100.00");
    expect(differenceOf("7", "7", 0)).toBe("0");
    expect(groupDigits("1234567.50")).toBe("1,234,567.50");
    expect(groupDigits("-1234.00")).toBe("-1,234.00");
    expect(groupDigits("250.00")).toBe("250.00");
    expect(groupDigits("+1000.00")).toBe("+1,000.00");
  });

  test("the channel checker's counts open the payments list with its filters, where the list has that filter", () => {
    const range = presetRange("7", TODAY);
    const bank = { id: 9, code: "e2e_bank_x_1" };
    expect(paymentsDrill(range, bank, "verified_count")).toEqual({ from: "2026-10-21", to: TODAY, channel_id: "9", status: "verified" });
    expect(paymentsDrill(range, bank, "disputed_count")).toEqual({ from: "2026-10-21", to: TODAY, channel_id: "9", status: "disputed" });
    expect(paymentsDrill(range, bank, "without_photo_count")).toEqual({ from: "2026-10-21", to: TODAY, channel_id: "9", missing_photo: "1" });
    // the list's missing_photo leaves cash out, and it has no filter for a missing reference or a duplicate one alone
    expect(paymentsDrill(range, { id: 1, code: "cash" }, "without_photo_count")).toBeNull();
    expect(paymentsDrill(range, bank, "without_reference_count")).toBeNull();
    expect(paymentsDrill(range, bank, "duplicate_reference_count")).toBeNull();
    // the payments list (step 4.5c) reads the drill back as its own filters
    const drill = paymentsDrill(range, bank, "without_photo_count") ?? {};
    expect(filterParams(filtersFromParams(params(drill)))).toEqual(drill);
  });

  test("exports are named in the browser as the API names them; margins over an incomplete cost are not shown as facts; prep times", () => {
    expect(exportFileName("channels", { from: "2026-10-21", to: TODAY }, "xlsx")).toBe("analytics-channels-2026-10-21-2026-10-27.xlsx");
    expect(exportFileName("items", { from: TODAY, to: TODAY }, "pdf")).toBe("analytics-items-2026-10-27-2026-10-27.pdf");
    expect(marginOf({ cogs_complete: true, cogs_incomplete_days: 0, margin_total: "40.00", margin_pct: "40.00" })).toEqual({ complete: true, margin: "40.00", pct: "40.00" });
    expect(marginOf({ cogs_complete: true, cogs_incomplete_days: 0, margin_total: "0.00", margin_pct: null })).toEqual({ complete: true, margin: "0.00", pct: null });
    // a display-case product made in the house has no cost of sales yet (4.4a): its "margin" is its whole revenue, never shown as one
    expect(marginOf({ cogs_complete: false, cogs_incomplete_days: 3, margin_total: "100.00", margin_pct: "100.00" })).toEqual({ complete: false, days: 3 });
    expect(prepTime(null)).toBeNull();
    expect(prepTime(59)).toEqual({ minutes: 0, seconds: 59 });
    expect(prepTime(252)).toEqual({ minutes: 4, seconds: 12 });
  });

  test("the charts scale decimal strings to pixels; the figures they show are the strings as given", () => {
    const values = ["10.00", "40.00", null, "20.00"];
    expect(extentOf(values)).toEqual({ min: "0", max: "40.00" });
    const bars = barsLayout(values, { width: 400, height: 100 });
    expect(bars.map((bar) => bar.height)).toEqual([25, 100, 0, 50]);
    expect(bars.map((bar) => bar.y)).toEqual([75, 0, 100, 50]);
    expect(bars.map((bar) => bar.missing)).toEqual([false, false, true, false]);
    expect(bars.map((bar) => bar.value)).toEqual(values);
    // four slots of 100 px, each bar 80 % of its slot, centred in it
    expect(bars.map((bar) => [bar.x, bar.width])).toEqual([
      [10, 80],
      [110, 80],
      [210, 80],
      [310, 80],
    ]);
    // money beyond what a float adds exactly still scales by its digits
    expect(barsLayout(["12345678901234.56", "24691357802469.12"], { width: 200, height: 100 }).map((bar) => bar.height)).toEqual([50, 100]);
    expect(barsLayout(["1", "3"], { width: 200, height: 100 }).map((bar) => bar.height)).toEqual([33.33, 100]);
    // a cash variance can be negative: the zero line moves up, a shortage hangs below it
    const signed = barsLayout(["-20.00", "20.00"], { width: 200, height: 100 });
    expect(extentOf(["-20.00", "20.00"])).toEqual({ min: "-20.00", max: "20.00" });
    expect(signed.map((bar) => [bar.y, bar.height, bar.negative])).toEqual([
      [50, 50, true],
      [0, 50, false],
    ]);
    // nothing to scale: every bar on the baseline, nothing divided by zero
    expect(barsLayout(["0.00", "0.00"], { width: 200, height: 100 }).map((bar) => [bar.y, bar.height])).toEqual([
      [100, 0],
      [100, 0],
    ]);
    expect(barsLayout([null, null], { width: 200, height: 100 }).every((bar) => bar.missing && bar.height === 0)).toBe(true);
    // a horizontal layout for categories (channels, order types): widths over the same scale
    expect(barsLayout(["25.00", "100.00"], { width: 200, height: 100 }, "horizontal").map((bar) => [bar.x, bar.width, bar.y, bar.height])).toEqual([
      [0, 50, 5, 40],
      [0, 200, 55, 40],
    ]);
  });

  test("a line breaks where a day was not built (never drawn as zero)", () => {
    const segments = lineSegments(["10", "40", null, "20", "30"], { width: 400, height: 100 });
    expect(segments.map((segment) => segment.map((point) => [point.x, point.y, point.value]))).toEqual([
      [
        [0, 75, "10"],
        [100, 0, "40"],
      ],
      [
        [300, 50, "20"],
        [400, 25, "30"],
      ],
    ]);
    expect(segments.map(pathOf)).toEqual(["M0 75 L100 0", "M300 50 L400 25"]);
    expect(lineSegments(["5"], { width: 400, height: 100 })).toEqual([[{ x: 200, y: 0, value: "5" }]]);
    expect(lineSegments([], { width: 400, height: 100 })).toEqual([]);
  });

  test.fixme("Ethiopian date labels on the dashboards (step 4.5f)", async () => {
    // TODO(p4): 4.5f: dayLabel and seriesLabel in lib/analytics.ts switch to the Ethiopian calendar when the person asks for it.
  });
});
