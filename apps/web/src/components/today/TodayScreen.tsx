"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useRef, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { addDays, agoOf, differenceOf, exportFileName, groupDigits, hourLabel, percentChange, type AnalyticsRange } from "@/lib/analytics";
import { useWorkStations } from "@/lib/api/adminShifts";
import { analyticsExport, useHourly, useOverview, type Answer, type HourlySales, type Overview } from "@/lib/api/analytics";
import { useReorderList, type ReorderRow } from "@/lib/api/inventory";
import type { Locale, WorkStation } from "@/lib/api/types";
import { ethiopianLabel } from "@/lib/dates";
import { compareDecimal, decimalRatio, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { createSystemFeed } from "@/lib/live/system";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { businessToday } from "@/lib/orderNo";
import { useProductImages } from "@/lib/productImages";
import { can, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { backupState, heartbeatState, type SystemStatus } from "@/lib/system";
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { useFreshness, useNames } from "../analytics/shared";
import { saveBlob } from "../payments/shared";
import { buttonClass, focusRing } from "../styles";
import { Alert, Chip, PageHeader, ProductImage, Skeleton, Status, type Tone } from "../ui";

// Admin > Today (step 6.4a; fresh-counter.md 4.2, the Admin board): the console's first page, the bakery's day at a glance. It reads only
// endpoints that already exist, each card for the people who hold its permission, so nobody meets an empty page:
//   - analytics.view (the admin, the manager): the title row's "Compare with last <weekday>" (the Sales page over the eight days) and
//     "Export day" (the day's sales report as Excel); the KPI row, Sales, Orders, Average ticket and Refunds, from GET /analytics/overview
//     for today, each against the same weekday a week before (a second overview of that one day); "Sales by hour"
//     (GET /analytics/sales/hourly, the current hour in brand teal); "Top sellers today" and "Payments by channel", the five best sellers and
//     the channels' shares the overview already carries, the sellers with the photo the catalog holds (useProductImages);
//   - inventory.view: "Stock alerts", the reorder list (GET /reorder-list), out first;
//   - stations.all: "Shifts", the stations and the shift running at each (GET /me work_stations, as the shifts screens read it);
//   - system.view (the admin): "System", the host's health in four lines (GET /system/status, the System page's feed);
//   - anyone without analytics.view: "My figures", their own (analytics.view_own, every role).
// "Updated ... ago" in the intro is the freshness feed's (GET /analytics/freshness), which also refreshes the figures when a rebuild moves
// today. A figure of a day not built yet is "—", never zero (R-U). Money is the API's decimal string, grouped, whole amounts without ".00".

const CARD = "rounded-[18px] border border-neutral-200 bg-white p-5";
const ROW_DIVIDER = "border-b border-neutral-100 last:border-b-0";
const LINK = `rounded-md text-sm font-semibold text-brand-600 hover:text-brand-700 ${focusRing}`;

/** An amount as the board writes it: digits grouped, a whole amount without its ".00" ("18,420", "112.30"). */
function amountText(amount: string): string {
  return groupDigits(amount).replace(/\.0+$/, "");
}

/** The current hour of the day in Addis Ababa (0 to 23). */
function addisHour(now: number): number {
  const hour = new Intl.DateTimeFormat("en-GB", { hour: "2-digit", hourCycle: "h23", timeZone: "Africa/Addis_Ababa" }).format(new Date(now));
  return Number.parseInt(hour, 10) % 24;
}

function weekdayOf(date: string, locale: Locale): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString(locale === "am" ? "am-ET" : "en-GB", { weekday: "long", timeZone: "UTC" });
}

function gregorianLong(date: string, locale: Locale): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString(locale === "am" ? "am-ET" : "en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  });
}

/** A card of the dashboard: a region named by its 17 / 600 heading, with an optional twin, a note or a link on the right. */
function TodayCard({
  title,
  twin,
  aside,
  children,
  className = "",
}: {
  title: string;
  twin?: { text: string; lang: "am" };
  aside?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${CARD} flex flex-col ${className}`}>
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-1.5">
          <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
            {title}
          </h2>
          {twin ? (
            <span lang={twin.lang} className="text-[17px] font-semibold text-neutral-900">
              <span aria-hidden="true">· </span>
              {twin.text}
            </span>
          ) : null}
        </div>
        {aside}
      </div>
      {children}
    </section>
  );
}

function CardNote({ children }: { children: ReactNode }) {
  return <span className="text-[13px] text-neutral-600">{children}</span>;
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="py-2 text-[15px] text-neutral-600">{children}</p>;
}

function Rows({ count = 4 }: { count?: number }) {
  return (
    <div className="space-y-3 py-1">
      {Array.from({ length: count }, (_, i) => (
        <Skeleton key={i} className="h-9" />
      ))}
    </div>
  );
}

export function TodayScreen({ titleAm, shiftsAm }: { titleAm?: string; shiftsAm?: string }) {
  const t = useTranslations("today");
  const locale = useLocale() as Locale;
  const me = useSession((s) => s.me);
  const full = can(me, "analytics.view");
  const today = businessToday();
  const range = useMemo<AnalyticsRange>(() => ({ preset: "today", from: today, to: today }), [today]);
  const weekAgo = addDays(today, -7);
  const before = useMemo<AnalyticsRange>(() => ({ preset: "custom", from: weekAgo, to: weekAgo }), [weekAgo]);
  const weekday = weekdayOf(today, locale);

  const overview = useOverview(range, false);
  const lastWeek = useOverview(before, false);
  const freshness = useFreshness(range, false, full && overview.isFetched);
  const errorText = useErrorText();
  const intro = useDayLine(today, freshness.freshness ? freshness.freshness.as_of : overview.data?.as_of, full);

  return (
    <>
      <PageHeader
        title={t("title")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        intro={intro}
        actions={full ? <DayActions range={range} weekday={weekday} today={today} /> : undefined}
      />
      <div className="flex flex-col gap-6">
        {full ? (
          <>
            {overview.error ? <Alert>{errorText.message(overview.error)}</Alert> : null}
            <Kpis answer={overview.data} before={lastWeek.data} weekday={weekday} />
            <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr))]">
              <SalesByHour range={range} />
              <TopSellers answer={overview.data} today={today} />
            </div>
          </>
        ) : null}
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))]">
          {full ? <Channels answer={overview.data} /> : <OwnFigures />}
          {can(me, "inventory.view") ? <StockAlerts /> : null}
          {can(me, "stations.all") ? <Shifts twin={shiftsAm} /> : null}
          {can(me, "system.view") ? <SystemCard /> : null}
        </div>
      </div>
    </>
  );
}

/**
 * The intro: today in both calendars, whatever the person's "Dates" choice (the board's line: it says which day it is in the two calendars
 * the bakery lives by), then, for the dashboards' readers, how fresh the figures are.
 */
function useDayLine(today: string, asOf: string | null | undefined, showAgo: boolean): string {
  const t = useTranslations("today");
  const locale = useLocale() as Locale;
  const fmt = useDateFormat();
  const now = useNow(30_000);
  const date = t("date_line", { gregorian: gregorianLong(today, locale), ethiopian: ethiopianLabel(today, fmt, true) });
  if (!showAgo || asOf === undefined) return date;
  const ago = agoOf(asOf, now);
  return t("date_line_ago", { date, ago: ago.unit === "never" ? t("updated_never") : t(`updated_${ago.unit}`, { count: ago.count }) });
}

/** The title row's actions: the comparison with the same weekday last week (the Sales page by day over both), and the day's export. */
function DayActions({ range, weekday, today }: { range: AnalyticsRange; weekday: string; today: string }) {
  const t = useTranslations("today");
  const locale = useLocale();
  const errorText = useErrorText();
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const running = useRef(false);

  async function exportDay() {
    if (running.current) return;
    running.current = true;
    setBusy(true);
    setError(null);
    setSaved(null);
    try {
      const blob = await analyticsExport("sales", range, "xlsx", locale);
      const name = exportFileName("sales", range, "xlsx");
      saveBlob(blob, name);
      setSaved(name);
    } catch (failure) {
      setError(failure);
    } finally {
      running.current = false;
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <div className="flex flex-wrap gap-2.5">
        <Link href={{ pathname: "/admin/analytics/sales", query: { from: addDays(today, -7), to: today } }} className={buttonClass({ variant: "secondary", size: "md" })}>
          {t("compare", { weekday })}
        </Link>
        <button type="button" disabled={busy} onClick={() => void exportDay()} className={buttonClass({ variant: "dark", size: "md", loading: busy })} aria-busy={busy || undefined}>
          {busy ? t("export_busy") : t("export")}
        </button>
      </div>
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <Status>{saved ? t("export_saved", { name: saved }) : null}</Status>
    </div>
  );
}

// --- the KPI row ------------------------------------------------------------------------------------------------------------------------

interface Delta {
  text: string;
  bad: boolean;
}

function Kpis({ answer, before, weekday }: { answer: Answer<Overview> | undefined; before: Answer<Overview> | undefined; weekday: string }) {
  const t = useTranslations("today");
  const built = answer ? answer.as_of !== null : false;
  const data = answer?.data;
  const then = before && before.as_of !== null ? before.data : null;

  /** A change in percent against the same weekday last week, as the board words it. */
  function change(current: string, previous: string | undefined): Delta | undefined {
    if (before === undefined) return undefined;
    if (previous === undefined || then === null) return { text: t("delta_none", { weekday }), bad: false };
    const pct = percentChange(current, previous);
    if (pct === null) return { text: t("delta_none", { weekday }), bad: false };
    // a rise comes signed ("+12.3"): the sign is told apart before the figure is compared
    const sign = compareDecimal(pct.replace(/^\+/, ""), "0");
    if (sign === 0) return { text: t("delta_same", { weekday }), bad: false };
    return { text: t(sign > 0 ? "delta_up" : "delta_down", { pct: pct.replace(/^[+-]/, ""), weekday }), bad: sign < 0 };
  }

  /** A change in money against the same weekday last week. */
  function moneyChange(current: string, previous: string | undefined): Delta | undefined {
    if (before === undefined) return undefined;
    if (previous === undefined || then === null) return { text: t("delta_none", { weekday }), bad: false };
    const difference = differenceOf(current, previous, 2);
    const sign = compareDecimal(difference.replace(/^\+/, ""), "0");
    if (sign === 0) return { text: t("delta_same", { weekday }), bad: false };
    return { text: t(sign > 0 ? "delta_money_up" : "delta_money_down", { amount: amountText(difference.replace(/^[+-]/, "")), weekday }), bad: sign < 0 };
  }

  const tiles: { key: string; label: string; value?: string; delta?: Delta }[] = [
    { key: "sales", label: t("kpi_sales"), value: data && t("money", { amount: amountText(data.revenue) }), delta: data && change(data.revenue, then?.revenue) },
    {
      key: "orders",
      label: t("kpi_orders"),
      value: data && groupDigits(String(data.orders_count)),
      delta: data && change(String(data.orders_count), then ? String(then.orders_count) : undefined),
    },
    { key: "avg", label: t("kpi_avg"), value: data && t("money", { amount: amountText(data.avg_ticket) }), delta: data && moneyChange(data.avg_ticket, then?.avg_ticket) },
    {
      key: "refunds",
      label: t("kpi_refunds"),
      value: data && t("money", { amount: amountText(data.refund_total) }),
      delta: data ? { text: t("refunds_line", { count: data.refund_count }), bad: false } : undefined,
    },
  ];

  return (
    <section aria-label={t("kpis")} className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))]">
      {tiles.map(({ key, label, value, delta }) => (
        <div key={key} role="group" aria-label={label} aria-busy={answer === undefined || undefined} className={`${CARD} flex flex-col gap-2`}>
          <p className="text-sm text-neutral-600">{label}</p>
          {answer === undefined ? (
            <>
              <Skeleton className="h-9 w-32" />
              <Skeleton className="h-4 w-40" />
            </>
          ) : (
            <>
              <p className="text-3xl font-bold tracking-tight tabular-nums text-neutral-900">{built ? value : "—"}</p>
              {built && delta ? (
                <p className={`text-[13px] font-semibold ${delta.bad ? "text-red-700" : "text-brand-700"}`}>{delta.text}</p>
              ) : !built ? (
                <p className="text-[13px] font-semibold text-neutral-600">{t("not_built")}</p>
              ) : null}
            </>
          )}
        </div>
      ))}
    </section>
  );
}

// --- sales by hour ------------------------------------------------------------------------------------------------------------------------

const FIRST_HOUR = 6;
const LAST_HOUR = 17;

/** The hours the chart shows: 06:00 to 17:00 as the board draws them, widened to any hour that sold, and to the hour it is now. */
function hourWindow(hours: HourlySales["hours"], nowHour: number): number[] {
  let first = FIRST_HOUR;
  let last = Math.max(LAST_HOUR, Math.min(nowHour, 23));
  for (const { hour, revenue } of hours) {
    if (compareDecimal(revenue, "0") <= 0) continue;
    first = Math.min(first, hour);
    last = Math.max(last, hour);
  }
  return Array.from({ length: last - first + 1 }, (_, i) => first + i);
}

function SalesByHour({ range }: { range: AnalyticsRange }) {
  const t = useTranslations("today");
  const hourly = useHourly(range);
  const now = useNow(60_000);
  const nowHour = addisHour(now);
  const answer = hourly.data;
  const hours = answer?.data.hours ?? [];
  const shown = hourWindow(hours, nowHour);
  const revenueAt = (hour: number) => hours.find((h) => h.hour === hour)?.revenue ?? "0";
  let peak: number | null = null;
  for (const hour of shown) if (compareDecimal(revenueAt(hour), "0") > 0 && (peak === null || compareDecimal(revenueAt(hour), revenueAt(peak)) > 0)) peak = hour;
  const max = peak === null ? null : revenueAt(peak);
  const columns = { gridTemplateColumns: `repeat(${shown.length}, minmax(0, 1fr))` };

  return (
    <TodayCard title={t("hourly_title")} aside={<CardNote>{t("hourly_meta", { from: hourLabel(shown[0]), to: hourLabel(shown[shown.length - 1]) })}</CardNote>}>
      {answer === undefined ? (
        hourly.error ? (
          <Empty>{t("hourly_error")}</Empty>
        ) : (
          <Skeleton className="h-[204px]" />
        )
      ) : (
        <>
          <div
            role="img"
            aria-label={peak === null || max === null ? t("hourly_label_empty") : t("hourly_label", { hour: hourLabel(peak), amount: amountText(max) })}
            className="grid h-45 items-end gap-2 border-b border-neutral-200 pb-1"
            style={columns}
          >
            {shown.map((hour) => {
              const revenue = revenueAt(hour);
              const height = max === null || compareDecimal(revenue, "0") <= 0 ? 0 : Math.max(2, decimalRatio(revenue, max, 1000) / 10);
              return (
                <div
                  key={hour}
                  className={`rounded-t-md ${hour === nowHour ? "bg-brand-600" : "bg-brand-100"}`}
                  style={{ height: `${height}%` }}
                />
              );
            })}
          </div>
          <div aria-hidden="true" className="mt-2 grid gap-2 text-center text-xs tabular-nums text-neutral-600" style={columns}>
            {shown.map((hour) => (
              <span key={hour}>{hour}</span>
            ))}
          </div>
        </>
      )}
    </TodayCard>
  );
}

// --- top sellers --------------------------------------------------------------------------------------------------------------------------

function TopSellers({ answer, today }: { answer: Answer<Overview> | undefined; today: string }) {
  const t = useTranslations("today");
  const names = useNames();
  const images = useProductImages();
  const rows = answer?.data.top_items ?? [];
  return (
    <TodayCard
      title={t("top_title")}
      aside={
        <Link href={{ pathname: "/admin/analytics/products", query: { from: today, to: today } }} className={LINK}>
          {t("top_all")}
        </Link>
      }
    >
      {answer === undefined ? (
        <Rows count={5} />
      ) : rows.length === 0 ? (
        <Empty>{answer.as_of === null ? t("not_built_long") : t("top_empty")}</Empty>
      ) : (
        <ol className="-mt-2">
          {rows.slice(0, 5).map((row, index) => {
            const name = names.item(row.item);
            return (
              <li key={row.item.id} className={`flex items-center gap-3 py-2 ${ROW_DIVIDER}`}>
                <span className="w-5.5 shrink-0 text-sm font-semibold tabular-nums text-neutral-600">{index + 1}</span>
                <ProductImage image={images.get(row.item.id) ?? null} name={name} category={names.category(row.item)} size="thumb" className="w-13 shrink-0 rounded-lg" />
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate text-[15px] font-semibold text-neutral-900">{name}</span>
                  <span className="text-[13px] text-neutral-600">{t("top_sold", { qty: groupDigits(trimDecimal(row.units_sold_qty)) })}</span>
                </span>
                <span className="shrink-0 text-[15px] font-semibold tabular-nums text-neutral-900">{t("money", { amount: amountText(row.revenue) })}</span>
              </li>
            );
          })}
        </ol>
      )}
    </TodayCard>
  );
}

// --- payments by channel -------------------------------------------------------------------------------------------------------------------

function Channels({ answer }: { answer: Answer<Overview> | undefined }) {
  const t = useTranslations("today");
  const names = useNames();
  const rows = answer?.data.channels ?? [];
  return (
    <TodayCard title={t("channels_title")} aside={<CardNote>{t("channels_meta")}</CardNote>}>
      {answer === undefined ? (
        <Rows count={4} />
      ) : rows.length === 0 ? (
        <Empty>{answer.as_of === null ? t("not_built_long") : t("channels_empty")}</Empty>
      ) : (
        <ul className="flex flex-col gap-3.5">
          {rows.map((row) => {
            const share = row.share === null ? null : trimDecimal(row.share);
            const width = row.share === null ? 0 : Math.min(100, decimalRatio(row.share, "100", 1000) / 10);
            return (
              <li key={row.channel.id} className="flex flex-col gap-1.5">
                <span className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="min-w-0 truncate font-semibold text-neutral-900">{names.channel(row.channel)}</span>
                  <span className="shrink-0 tabular-nums text-neutral-600">
                    {share === null ? t("money", { amount: amountText(row.amount) }) : t("channels_line", { amount: amountText(row.amount), share })}
                  </span>
                </span>
                <span aria-hidden="true" className="block h-2.5 overflow-hidden rounded-full bg-neutral-100">
                  <span className="block h-2.5 rounded-full bg-brand-600" style={{ width: `${width}%` }} />
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </TodayCard>
  );
}

// --- stock alerts ---------------------------------------------------------------------------------------------------------------------------

const STOCK_ROWS = 4;

function StockAlerts() {
  const t = useTranslations("today");
  const ti = useTranslations("inventory");
  const locale = useLocale() as Locale;
  const list = useReorderList();
  const named = (row: { name_en: string; name_am: string }) => (locale === "am" ? row.name_am : row.name_en);
  const rows: ReorderRow[] = [...(list.data ?? [])].sort((a, b) => (a.state === b.state ? 0 : a.state === "out" ? -1 : 1));
  const more = Math.max(0, rows.length - STOCK_ROWS);
  return (
    <TodayCard
      title={t("stock_title")}
      aside={
        <Link href="/admin/inventory/reorder" className={LINK}>
          {t("stock_plan")}
        </Link>
      }
    >
      {list.isPending ? (
        <Rows count={3} />
      ) : list.isError ? (
        <Empty>{t("stock_error")}</Empty>
      ) : rows.length === 0 ? (
        <Empty>{t("stock_none")}</Empty>
      ) : (
        <>
          <ul className="-mt-2">
            {rows.slice(0, STOCK_ROWS).map((row) => {
              const place = row.location ? named(row.location) : ti("whole_branch");
              return (
                <li key={`${row.item.id}-${row.location?.id ?? 0}`} className={`flex items-center justify-between gap-3 py-2 ${ROW_DIVIDER}`}>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-[15px] font-semibold text-neutral-900">{named(row.item)}</span>
                    <span className="truncate text-[13px] text-neutral-600">
                      {row.state === "out" ? t("stock_out_line", { place }) : t("stock_left", { place, qty: groupDigits(trimDecimal(row.available)), unit: row.item.unit })}
                    </span>
                  </span>
                  <Chip tone={row.state === "out" ? "red" : "amber"} className="shrink-0">
                    {ti(`state.${row.state}`)}
                  </Chip>
                </li>
              );
            })}
          </ul>
          {more > 0 ? <p className="mt-2 text-[13px] text-neutral-600">{t("stock_more", { count: more })}</p> : null}
        </>
      )}
    </TodayCard>
  );
}

// --- shifts -------------------------------------------------------------------------------------------------------------------------------

function shiftChip(station: WorkStation): { tone: Tone; key: string } | null {
  if (!station.tracks_shifts) return null;
  const shift = station.current_shift;
  if (!shift) return { tone: "amber", key: "badge_none" };
  if (shift.status === "opening") return { tone: "blue", key: "badge_opening" };
  if (shift.status === "counting") return { tone: "blue", key: "badge_counting" };
  return { tone: "brand", key: "badge_open" };
}

function Shifts({ twin }: { twin?: string }) {
  const t = useTranslations("today");
  const ts = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const me = useSession((s) => s.me);
  // the same query (and so the same cached shape) as the admin shifts screens the card links to, looked at again every 15 s
  const fresh = useWorkStations({ refetchInterval: 15_000 });
  const stations = fresh.data ?? me?.work_stations ?? [];
  return (
    <TodayCard
      title={t("shifts_title")}
      twin={twin ? { text: twin, lang: "am" } : undefined}
      aside={
        <Link href="/admin/shifts/roster" className={LINK}>
          {t("shifts_roster")}
        </Link>
      }
    >
      {stations.length === 0 ? (
        <Empty>{ts("stations_none")}</Empty>
      ) : (
        <ul className="-mt-1.5">
          {stations.map((station) => {
            const chip = shiftChip(station);
            const opened = station.current_shift?.opened_at ?? null;
            return (
              <li key={station.station_id} className={`flex items-center justify-between gap-3 py-1.5 ${ROW_DIVIDER}`}>
                <span className="flex min-w-0 flex-col">
                  <span className="truncate text-[15px] font-semibold text-neutral-900">{locale === "am" ? station.name_am : station.name_en}</span>
                  <span className="truncate text-[13px] text-neutral-600">
                    {!station.tracks_shifts ? ts("station_no_tracking") : opened ? ts("open_since", { time: addisClock(opened) }) : t("shifts_none_open")}
                  </span>
                </span>
                {chip ? (
                  <Chip tone={chip.tone} className="shrink-0">
                    {ts(chip.key)}
                  </Chip>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </TodayCard>
  );
}

// --- system -------------------------------------------------------------------------------------------------------------------------------

const HEARTBEAT_TONE = { running: "brand", stopped: "red", never: "amber" } as const;
const BACKUP_TONE = { ok: "brand", stale: "red", failed: "red", running: "blue", none: "neutral" } as const;

function SystemCard() {
  const t = useTranslations("today");
  const ts = useTranslations("system");
  const signedIn = useSession((s) => s.status === "signed-in");
  const feed = useMemo(() => (signedIn ? createSystemFeed() : null), [signedIn]);
  const snapshot = useLiveFeed(feed);
  const status: SystemStatus | null = snapshot?.state.status ?? null;
  const now = useNow(15_000);

  const lines: { key: string; label: string; detail: string; chip?: { tone: Tone; text: string } }[] = [];
  if (status) {
    const beat = heartbeatState(status.scheduler, now);
    const backup = backupState(status.backups, now);
    lines.push(
      { key: "scheduler", label: ts("scheduler.title"), detail: t("system_scheduler"), chip: { tone: HEARTBEAT_TONE[beat], text: ts(`scheduler.${beat}`) } },
      { key: "backups", label: ts("backups.title"), detail: t("system_backups"), chip: { tone: BACKUP_TONE[backup], text: ts(`backups.${backup}`) } },
      {
        key: "queue",
        label: ts("queue.title"),
        detail: t("system_queue", { count: status.queue.pending }),
        chip: status.queue.failed_24h > 0 ? { tone: "red", text: t("system_failed", { count: status.queue.failed_24h }) } : undefined,
      },
      { key: "devices", label: ts("devices.title"), detail: t("system_devices", { seen: status.devices.seen_15m, enrolled: status.devices.enrolled }) },
    );
  }

  return (
    <TodayCard
      title={t("system_title")}
      aside={
        <Link href="/admin/system" className={LINK}>
          {t("system_open")}
        </Link>
      }
    >
      {status === null ? (
        snapshot?.error ? (
          <Empty>{t("system_error")}</Empty>
        ) : (
          <Rows count={4} />
        )
      ) : (
        <ul className="-mt-1.5">
          {lines.map(({ key, label, detail, chip }) => (
            <li key={key} className={`flex items-center justify-between gap-3 py-1.5 ${ROW_DIVIDER}`}>
              <span className="flex min-w-0 flex-col">
                <span className="truncate text-[15px] font-semibold text-neutral-900">{label}</span>
                <span className="truncate text-[13px] text-neutral-600">{detail}</span>
              </span>
              {chip ? (
                <Chip tone={chip.tone} className="shrink-0">
                  {chip.text}
                </Chip>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </TodayCard>
  );
}

// --- my figures ---------------------------------------------------------------------------------------------------------------------------

/** For a person without the dashboards (analytics.view_own, every role): their own figures, so Today is never empty. */
function OwnFigures() {
  const t = useTranslations("today");
  return (
    <TodayCard title={t("own_title")}>
      <p className="text-[15px] text-neutral-600">{t("own_body")}</p>
      <div className="mt-4">
        <Link href="/admin/analytics/me" className={buttonClass({ variant: "secondary", size: "md" })}>
          {t("own_open")}
        </Link>
      </div>
    </TodayCard>
  );
}
