"use client";

import { useQueryClient } from "@tanstack/react-query";
import { CalendarRange, CircleAlert, Download } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { usePathname, useRouter } from "@/i18n/navigation";
import {
  agoOf,
  changedDays,
  dayLabel,
  dayRuns,
  exportFileName,
  EXPORT_FORMATS,
  freshnessRange,
  groupDigits,
  PRESETS,
  prepTime,
  presetRange,
  queryTouches,
  rangeFromParams,
  rangeIssue,
  rangeLabel,
  rangeParams,
  rebuildPlan,
  spanLabel,
  type AnalyticsRange,
  type DayRange,
  type ExportFormat,
  type RangeIssue,
  type RebuildPiece,
} from "@/lib/analytics";
import { analyticsExport, rebuildRange, type ChannelName, type ItemName, type PersonName, type ReportName, type StationName } from "@/lib/api/analytics";
import { uuid } from "@/lib/api/idempotency";
import type { Locale } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { createFreshnessFeed } from "@/lib/live/analytics";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { businessToday } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import { can, useSession } from "@/lib/session";
import { canOpen } from "@/lib/access";
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { addisStamp, saveBlob } from "../payments/shared";
import { CARD, Notice, SectionTabs, Toolbar, ToolbarField } from "../admin/kit";
import { NoAccess } from "../RequireAuth";
import { Alert, Button, HelpTip, PageHeader, Skeleton, Status } from "../ui";
import { focusRing } from "../styles";

// Step 6.4d (fresh-counter.md 4.4, the Admin and CatalogList boards): the title row with its Amharic twin and the exports as its actions,
// the sections as segmented tabs under it, the range and the page's options as the toolbar (the freshness pill in the title row), the KPI
// tiles as the board's Stats, the sections as white 18 px cards; the tables are the kit's TableCard (NUM for a figure's cell).
//
// Pieces the analytics screens share (step 4.5e): the section's pills, the date range kept in the page's address, the freshness badge and
// the notice of days not built with its Rebuild button, the export buttons, KPI tiles, skeletons, and how names and figures read. Every
// figure is shown as the API sent it (digits grouped, a quantity without its padding zeros), never recomputed with a float.

// --- the address ------------------------------------------------------------------------------------------------------------------------------

export interface AnalyticsPage {
  params: URLSearchParams;
  range: AnalyticsRange;
  today: string;
  /** the page's address with these query parameters (the range is kept unless `next` names it) */
  go: (next: Record<string, string>) => void;
  /** a link's query that keeps the range */
  withRange: (extra?: Record<string, string>) => Record<string, string>;
}

export function useAnalyticsPage(): AnalyticsPage {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const today = businessToday();
  const params = useMemo(() => new URLSearchParams(searchParams.toString()), [searchParams]);
  const range = useMemo(() => rangeFromParams(params, today), [params, today]);
  return {
    params,
    range,
    today,
    go: (next) => router.replace({ pathname, query: next }),
    withRange: (extra = {}) => ({ ...rangeParams(range), ...extra }),
  };
}

/** The page's own options in the address (group, kind, sort...), with the range. */
export function pageQuery(range: AnalyticsRange, options: Record<string, string | boolean | null | undefined>): Record<string, string> {
  const query: Record<string, string> = rangeParams(range);
  for (const [key, value] of Object.entries(options)) {
    if (value === true) query[key] = "1";
    else if (typeof value === "string" && value !== "") query[key] = value;
  }
  return query;
}

// --- the pills ----------------------------------------------------------------------------------------------------------------------------

const SECTIONS = [
  { href: "/admin/analytics", key: "overview" },
  { href: "/admin/analytics/sales", key: "sales" },
  { href: "/admin/analytics/channels", key: "channels" },
  { href: "/admin/analytics/products", key: "products" },
  { href: "/admin/analytics/stations", key: "stations" },
  { href: "/admin/analytics/staff", key: "staff" },
  { href: "/admin/analytics/shifts", key: "shifts" },
  { href: "/admin/analytics/me", key: "me" },
] as const;

/**
 * The analytics pages the person may open, as the admin's segmented tabs under each page's title row (step 6.4d, kit.tsx SectionTabs):
 * still the navigation "Analytics sections" with the same link names; each link keeps the range (the product page belongs to Products).
 */
export function AnalyticsNav() {
  const t = useTranslations("analytics");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const params = useSearchParams();
  const query = new URLSearchParams(rangeParams(rangeFromParams(params, businessToday()))).toString();
  const tabs = SECTIONS.filter((section) => canOpen(me, section.href)).map(({ href, key }) => ({
    href: query ? `${href}?${query}` : href,
    label: t(`nav.${key}`),
    current: pathname === href || (key === "products" && pathname === "/admin/analytics/product"),
  }));
  if (tabs.length === 0) return null;
  return <SectionTabs label={t("sections")} tabs={tabs} />;
}

/**
 * The title row of an analytics page: its title with the Amharic twin, the intro, and on the right how fresh the figures are (`fresh`,
 * Today's "updated … ago") beside the page's exports.
 */
export function AnalyticsHeader({
  title,
  intro,
  titleAm,
  report,
  range,
  fresh,
}: {
  title: string;
  intro?: string;
  titleAm?: string;
  report?: ReportName;
  range?: AnalyticsRange;
  fresh?: ReactNode;
}) {
  const exports = report && range ? <ExportButtons report={report} range={range} /> : null;
  return (
    <PageHeader
      title={title}
      intro={intro}
      twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
      actions={
        fresh || exports ? (
          <>
            {fresh}
            {exports}
          </>
        ) : undefined
      }
    />
  );
}

/**
 * The pages of analytics.view. A person who holds only analytics.view_own (every role) is taken to their own page, so the console's card
 * and any link land somewhere they may read; anyone else is told the page is not theirs.
 */
export function AnalyticsGate({ children }: { children: ReactNode }) {
  const t = useTranslations();
  const router = useRouter();
  const me = useSession((s) => s.me);
  const full = can(me, "analytics.view");
  const own = can(me, "analytics.view_own");
  useEffect(() => {
    if (me && !full && own) router.replace("/admin/analytics/me");
  }, [me, full, own, router]);
  if (full) return <>{children}</>;
  if (own)
    return (
      <p role="status" className="text-base text-neutral-600">
        {t("analytics.opening_own")}
      </p>
    );
  return <NoAccess />;
}

// --- the range ----------------------------------------------------------------------------------------------------------------------------

/** A pressed button of a segment track (the catalog's segments: 36 px in a 46 px white track, the pressed one ink-100). */
function segmentClass(pressed: boolean): string {
  return `inline-flex h-9 items-center gap-1 rounded-[9px] px-3.5 text-sm font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
    pressed ? "bg-ink-100 text-ink-900" : "text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
  } ${focusRing}`;
}

const TRACK = "flex max-w-full flex-wrap gap-1 rounded-xl border border-neutral-200 bg-white p-1";

/**
 * The page's toolbar (step 6.4d, the catalog's toolbar): the range as a segment track (Today, 7, 30 or 90 days, or a custom range in the
 * branch's local dates; the choice goes to the address), the range in words, the page's own options (`children`: a grouping, a filter)
 * and, at the right, `aside` (how fresh the figures are). The custom range's two dates open under it.
 */
export function RangeControl({ range, today, onChange, children, aside }: { range: AnalyticsRange; today: string; onChange: (range: AnalyticsRange) => void; children?: ReactNode; aside?: ReactNode }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const [custom, setCustom] = useState(range.preset === "custom");
  return (
    <section aria-label={t("range.title")} className="space-y-3">
      <Toolbar>
        <div role="group" aria-label={t("range.title")} className={TRACK}>
          {PRESETS.map((preset) => (
            <button
              key={preset}
              type="button"
              aria-pressed={range.preset === preset}
              className={segmentClass(range.preset === preset)}
              onClick={() => {
                setCustom(false);
                onChange(presetRange(preset, today));
              }}
            >
              {t(`range.preset_${preset}`)}
            </button>
          ))}
          <button type="button" aria-pressed={range.preset === "custom"} aria-expanded={custom} className={segmentClass(range.preset === "custom")} onClick={() => setCustom((open) => !open)}>
            <CalendarRange className="size-4" strokeWidth={2} aria-hidden="true" />
            {t("range.custom")}
          </button>
        </div>
        <p className="text-[15px] font-semibold tabular-nums text-neutral-700">{rangeLabel(range, dates)}</p>
        {children}
        {aside ? <div className="ml-auto flex flex-wrap items-center gap-2">{aside}</div> : null}
      </Toolbar>
      {custom ? <CustomRange key={`${range.from}|${range.to}`} range={range} today={today} onApply={onChange} /> : null}
    </section>
  );
}

function CustomRange({ range, today, onApply }: { range: DayRange; today: string; onApply: (range: AnalyticsRange) => void }) {
  const t = useTranslations("analytics");
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [issue, setIssue] = useState<RangeIssue | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found = rangeIssue(from, to, today);
    setIssue(found);
    if (found === null) onApply({ preset: "custom", from, to });
  }

  return (
    <form onSubmit={onSubmit} noValidate aria-label={t("range.custom_form")}>
      <Toolbar className="items-start">
        <ToolbarField label={t("range.from")} type="date" max={today} value={from} error={issue ? t(`range.issue_${issue}`) : undefined} onChange={(event) => setFrom(event.target.value)} />
        <ToolbarField label={t("range.to")} type="date" max={today} value={to} onChange={(event) => setTo(event.target.value)} />
        <Button type="submit" size="md">
          {t("range.apply")}
        </Button>
      </Toolbar>
    </form>
  );
}

/** A row of choices as a segment track of pressed buttons (group by day, week or month; a kind of work), named by `label`. */
export function Choices<T extends string>({ label, options, value, onChange, text }: { label: string; options: readonly T[]; value: T; onChange: (value: T) => void; text: (option: T) => string }) {
  return (
    <div role="group" aria-label={label} className={TRACK}>
      {options.map((option) => (
        <button key={option || "all"} type="button" aria-pressed={value === option} className={segmentClass(value === option)} onClick={() => onChange(option)}>
          {text(option)}
        </button>
      ))}
    </div>
  );
}

// --- freshness ----------------------------------------------------------------------------------------------------------------------------

/**
 * The freshness feed of the days the screen reads: its answer for the badge and the notice, and the invalidation of the queries whose days
 * moved (the dashboards are not polled; their ETag answers 304 until a rebuild). `enabled` lets the overview start it once its own first
 * figures are in, so nothing competes with them.
 */
export function useFreshness(range: DayRange, compare = false, enabled = true) {
  const allowed = useSession((s) => s.status === "signed-in" && can(s.me, "analytics.view_own"));
  const span = freshnessRange(range, compare);
  const spanKey = `${span.from}|${span.to}`;
  // eslint-disable-next-line react-hooks/exhaustive-deps -- one feed per span; the key holds both of its days
  const feed = useMemo(() => (allowed && enabled ? createFreshnessFeed(span) : null), [spanKey, allowed, enabled]);
  const snapshot = useLiveFeed(feed);
  const client = useQueryClient();
  const current = snapshot?.state.current ?? null;
  const before = snapshot?.state.before ?? null;
  useEffect(() => {
    if (!current || !before) return;
    const moved = changedDays(before.days, current.days);
    if (moved.length > 0) void client.invalidateQueries({ predicate: (query) => queryTouches(query.queryKey, moved) });
  }, [current, before, client]);
  return { freshness: current, error: snapshot?.error ?? null, loaded: snapshot?.loaded ?? false, refresh: () => feed?.refresh() };
}

type Freshness = ReturnType<typeof useFreshness>;

/**
 * "Updated 2 min ago" (the oldest rebuild of the days read): a white pill in the title row, as Today's "updated … ago", its dot positive,
 * or amber while nothing is built.
 */
export function FreshnessChip({ state, asOf }: { state: Freshness; asOf?: string | null }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const now = useNow(30_000);
  const freshness = state.freshness;
  const stamp = freshness ? freshness.as_of : (asOf ?? null);
  const ago = agoOf(stamp, now);
  if (freshness === null && asOf === undefined) return null;
  return (
    <span className="inline-flex min-h-9 items-center gap-2 rounded-full border border-neutral-200 bg-white px-3.5 text-[13px] font-semibold text-neutral-700">
      <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${ago.unit === "never" ? "bg-amber-700" : "bg-positive-600"}`} />
      <span title={stamp ? addisStamp(stamp, dates) : undefined}>{ago.unit === "never" ? t("fresh.never") : t(`fresh.${ago.unit}`, { count: ago.count })}</span>
    </span>
  );
}

/**
 * Under the toolbar: a rebuild that is running, a freshness feed that cannot be read, the days not calculated with the Rebuild button.
 * Today alone is not a warning (the explain-itself follow-up, Ramin 2026-11-10: "Days not built" read like a fault): its figures are
 * calculated about a minute after each sale, so it gets a calm blue line and no button; only past days are named as not calculated.
 */
export function FreshnessNotice({ state }: { state: Freshness }) {
  const t = useTranslations("analytics");
  const freshness = state.freshness;
  // said here, not in the notice: an inline rebuild builds the days, and the notice goes with the next look of the feed
  const [done, setDone] = useState<string | null>(null);
  const today = businessToday();
  const past = freshness ? freshness.stale_days.filter((day) => day !== today) : [];
  const todayMissing = !!freshness && freshness.stale_days.includes(today);
  return (
    <>
      {freshness?.running ? (
        <Notice tone="blue" live>
          {t("fresh.running")}
        </Notice>
      ) : null}
      {state.error && state.loaded ? <Notice tone="amber">{t("fresh.stale_feed")}</Notice> : null}
      {todayMissing && past.length === 0 && !freshness?.running ? <Notice tone="blue">{t("stale.today")}</Notice> : null}
      {freshness && past.length > 0 ? <NotBuilt days={past} running={freshness.running} onRebuilt={state.refresh} onDone={setDone} /> : null}
      <Status>{done}</Status>
    </>
  );
}

/** The days of the range with no summaries: named (never counted as zero), and rebuilt on request by an admin (analytics.manage). */
function NotBuilt({ days, running, onRebuilt, onDone }: { days: string[]; running: boolean; onRebuilt: () => void; onDone: (message: string | null) => void }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const errorText = useErrorText();
  const client = useQueryClient();
  const headingId = useId();
  const manager = useSession((s) => can(s.me, "analytics.manage"));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const keys = useRef(new Map<string, string>());

  async function rebuild() {
    if (busy) return;
    setBusy(true);
    setError(null);
    onDone(null);
    let built = 0;
    let queued = 0;
    try {
      for (const piece of rebuildPlan(days)) {
        const name = pieceName(piece);
        const key = keys.current.get(name) ?? uuid();
        keys.current.set(name, key);
        try {
          const answer = await rebuildRange(piece, key);
          keys.current.delete(name);
          if (answer.sync) built += answer.days.length;
          else queued += answer.queued;
          const moved = answer.sync ? answer.days.map((day) => day.day) : [];
          if (moved.length > 0) void client.invalidateQueries({ predicate: (query) => queryTouches(query.queryKey, moved) });
        } catch (failure) {
          // the outcome is unknown: the same key replays it; a refusal took nothing, so the next try asks anew
          if (!keepsKey(failure)) keys.current.delete(name);
          throw failure;
        }
      }
      onDone(queued > 0 ? t("stale.queued", { count: queued + built }) : t("stale.built", { count: built }));
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(false);
      onRebuilt();
    }
  }

  const runs = dayRuns(days);
  return (
    <section aria-labelledby={headingId} className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
      <CircleAlert className="mt-0.5 size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
      <div className="min-w-0 flex-1 space-y-2.5">
        <div className="flex items-center gap-1">
          <h2 id={headingId} className="text-[15px] font-semibold">
            {t("stale.title")}
          </h2>
          <HelpTip label={t("stale.help_label")}>{t("stale.help")}</HelpTip>
        </div>
        <p className="text-[15px]">{t("stale.body", { count: days.length, days: runs.map((run) => spanLabel(run, dates)).join(", ") })}</p>
        {manager ? (
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="secondary" size="md" disabled={busy || running} onClick={() => void rebuild()}>
              {busy || running ? t("stale.rebuilding") : t("stale.rebuild")}
            </Button>
          </div>
        ) : (
          <p className="text-[13px]">{t("stale.ask_admin")}</p>
        )}
        {error ? <Alert>{errorText.message(error)}</Alert> : null}
      </div>
    </section>
  );
}

function pieceName(piece: RebuildPiece): string {
  return `${piece.from}|${piece.to}|${piece.sync ? 1 : 0}`;
}

// --- exports ------------------------------------------------------------------------------------------------------------------------------

/**
 * Excel, CSV and PDF of a report over the range, in the page's language, read with the bearer token and named in the browser: the title
 * row's actions (step 6.4d), a group named "Export" and described by what the file holds, the outcome in one short line under them.
 */
export function ExportButtons({ report, range }: { report: ReportName; range: AnalyticsRange }) {
  const t = useTranslations("analytics");
  const locale = useLocale();
  const errorText = useErrorText();
  const [busy, setBusy] = useState<ExportFormat | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const running = useRef(false);

  async function download(format: ExportFormat) {
    if (running.current) return;
    running.current = true;
    setBusy(format);
    setError(null);
    setSaved(null);
    try {
      const blob = await analyticsExport(report, range, format, locale);
      const name = exportFileName(report, range, format);
      saveBlob(blob, name);
      setSaved(name);
    } catch (failure) {
      setError(failure);
    } finally {
      running.current = false;
      setBusy(null);
    }
  }

  const hintId = useId();
  return (
    <div className="flex flex-col items-end gap-1.5">
      <div role="group" aria-label={t("export.title")} aria-describedby={hintId} className="flex flex-wrap justify-end gap-2">
        {EXPORT_FORMATS.map((format) => (
          <Button key={format} variant="secondary" size="md" compact disabled={busy !== null} onClick={() => void download(format)}>
            <Download className="size-4" strokeWidth={2} aria-hidden="true" />
            {busy === format ? t("export.busy") : t(`export.${format}`)}
          </Button>
        ))}
      </div>
      <p id={hintId} className="sr-only">
        {t("export.hint")}
      </p>
      {error ? (
        <p role="alert" className="max-w-md text-right text-[13px] font-medium text-red-700">
          {errorText.message(error)}
        </p>
      ) : null}
      <p role="status" className={saved ? "max-w-md truncate text-right text-[13px] font-medium text-positive-700" : "sr-only"}>
        {saved ? t("export.saved", { name: saved }) : null}
      </p>
    </div>
  );
}

// --- layout pieces ------------------------------------------------------------------------------------------------------------------------

/** The board's card: white, a neutral-200 hairline, 18 px corners, 20 px padding. */
const PANEL = `${CARD} p-5`;

/**
 * A headline figure, the board's Stat (Today's KPI row): a white card, the label (14, neutral-600), the value (30 / 700, tabular), then a
 * detail and the change against the period before (13 / 600). Named for screen readers and tests by its label; a grey block while its
 * query loads. `bad` reads the change in red.
 */
export function Kpi({
  label,
  value,
  detail,
  change,
  bad = false,
  loading,
  children,
}: {
  label: string;
  value?: ReactNode;
  detail?: ReactNode;
  change?: ReactNode;
  bad?: boolean;
  loading?: boolean;
  children?: ReactNode;
}) {
  return (
    <div role="group" aria-label={label} aria-busy={loading || undefined} className={`${PANEL} flex min-h-32 flex-col gap-2`}>
      <p className="text-sm text-neutral-600">{label}</p>
      {loading ? (
        <>
          <Skeleton className="h-9 w-32" />
          <Skeleton className="h-4 w-40" />
        </>
      ) : (
        <p className="text-3xl font-bold tracking-tight tabular-nums text-neutral-900">{value}</p>
      )}
      {!loading && detail ? <p className="text-[13px] font-semibold text-neutral-600">{detail}</p> : null}
      {!loading && change ? <p className={`text-[13px] font-semibold ${bad ? "text-red-700" : "text-positive-700"}`}>{change}</p> : null}
      {!loading ? children : null}
    </div>
  );
}

/** Grey rows while a table's query loads (the shape of what is coming, so the page does not jump). */
export function SkeletonRows({ rows = 5, label }: { rows?: number; label: string }) {
  return (
    <div role="status" aria-label={label} className="space-y-3 py-1">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-7" />
      ))}
    </div>
  );
}

/** A section of a dashboard: a white card named by its 17 / 600 title, with a note or a control on the right. */
export function Section({ title, children, actions, className = "" }: { title: string; children: ReactNode; actions?: ReactNode; className?: string }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${PANEL} flex flex-col ${className}`}>
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
          {title}
        </h2>
        {actions}
      </div>
      {children}
    </section>
  );
}

/** A read that failed: the API's own message in the page's language (a range it will not read is analytics.range.*), and a retry. */
export function ReadError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const t = useTranslations("analytics");
  const errorText = useErrorText();
  return (
    <div className="space-y-3">
      <Alert>{errorText.message(error)}</Alert>
      <Button variant="secondary" size="md" onClick={onRetry}>
        {t("retry")}
      </Button>
    </div>
  );
}

// --- names and figures --------------------------------------------------------------------------------------------------------------------

type Bilingual = { name_en?: string; name_am?: string };

function bilingual(locale: Locale, row: Bilingual): string | undefined {
  return (locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am) || undefined;
}

/** Names in the page's language; a row the API could not name any more keeps its number, a removed one says so. */
export function useNames() {
  const t = useTranslations("analytics");
  const locale = useLocale() as Locale;
  return {
    channel: (channel: ChannelName): string => {
      const name = bilingual(locale, channel) ?? channel.code ?? t("unnamed", { id: channel.id });
      return channel.is_removed ? t("removed_name", { name }) : name;
    },
    item: (item: ItemName): string => bilingual(locale, item) ?? t("unnamed", { id: item.id }),
    category: (item: ItemName): string | null => (item.category ? (bilingual(locale, item.category) ?? null) : null),
    station: (station: StationName): string => {
      const name = bilingual(locale, station) ?? station.code ?? t("unnamed", { id: station.id });
      return station.is_active === false ? t("removed_name", { name }) : name;
    },
    person: (person: PersonName): string => person.name || t("unnamed", { id: person.id }),
  };
}

/** How figures read: money with its currency or plain in a table, quantities without padding zeros, counts, percents, prep times. */
export function useFigures() {
  const t = useTranslations("analytics");
  return {
    money: (amount: string | null): string => (amount === null ? "—" : t("money", { amount: groupDigits(amount) })),
    amount: (amount: string | null): string => (amount === null ? "—" : groupDigits(amount)),
    qty: (qty: string | null): string => (qty === null ? "—" : groupDigits(trimDecimal(qty))),
    count: (count: number | null): string => (count === null ? "—" : groupDigits(String(count))),
    pct: (pct: string | null): string => (pct === null ? "—" : t("pct", { value: pct })),
    prep: (seconds: number | null): string => {
      const time = prepTime(seconds);
      return time === null ? "—" : t("prep", time);
    },
  };
}

/** A local date as the dashboards label it (lib/analytics.ts dayLabel), in the person's calendar (step 4.5f). */
export function useDayLabel(): (day: string, withYear?: boolean) => string {
  const dates = useDateFormat();
  return (day, withYear = false) => dayLabel(day, dates, withYear);
}

/** A figure's cell (and its header): right-aligned in tabular numbers, on one line (the board's Price column). */
export const NUM = "whitespace-nowrap text-right tabular-nums";

/** A cell that says a point was not built, across the figures of its row. */
export const NOT_BUILT = "text-right text-neutral-600";

/** A link of a table (a product's name to its page): the brand red, underlined on hover, 44 px tall for a finger. */
export const ROW_LINK = `-my-1 inline-flex min-h-11 items-center rounded-md font-semibold text-brand-600 hover:text-brand-700 hover:underline ${focusRing}`;
