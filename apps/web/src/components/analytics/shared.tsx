"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
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
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { addisStamp, saveBlob } from "../payments/shared";
import { Alert, Badge, Button, Field, Status, TextInput } from "../ui";
import { focusRing } from "../styles";

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
  { href: "/admin/analytics", key: "overview", permission: "analytics.view" },
  { href: "/admin/analytics/sales", key: "sales", permission: "analytics.view" },
  { href: "/admin/analytics/channels", key: "channels", permission: "analytics.view" },
  { href: "/admin/analytics/products", key: "products", permission: "analytics.view" },
  { href: "/admin/analytics/stations", key: "stations", permission: "analytics.view" },
  { href: "/admin/analytics/staff", key: "staff", permission: "analytics.view" },
  { href: "/admin/analytics/shifts", key: "shifts", permission: "analytics.view" },
  { href: "/admin/analytics/me", key: "me", permission: "analytics.view_own" },
] as const;

/** The analytics pages the person may open, as pills that keep the range (the product page belongs to Products). */
export function AnalyticsNav() {
  const t = useTranslations("analytics");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const params = useSearchParams();
  const range = rangeFromParams(params, businessToday());
  return (
    <nav aria-label={t("sections")} className="mb-6 flex flex-wrap gap-2">
      {SECTIONS.filter((section) => can(me, section.permission)).map(({ href, key }) => {
        const current = pathname === href || (key === "products" && pathname === "/admin/analytics/product");
        return (
          <Link
            key={href}
            href={{ pathname: href, query: rangeParams(range) }}
            aria-current={current ? "page" : undefined}
            className={`inline-flex min-h-12 items-center rounded-full border px-5 text-base ${
              current ? "border-neutral-900 bg-neutral-900 text-white" : "border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {t(`nav.${key}`)}
          </Link>
        );
      })}
    </nav>
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
  return (
    <div className="max-w-xl">
      <Alert>{t("admin.no_access")}</Alert>
    </div>
  );
}

// --- the range ----------------------------------------------------------------------------------------------------------------------------

/** Today, 7, 30 or 90 days, or a custom range in the branch's local dates; the choice goes to the address. */
export function RangeControl({ range, today, onChange, children }: { range: AnalyticsRange; today: string; onChange: (range: AnalyticsRange) => void; children?: ReactNode }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const [custom, setCustom] = useState(range.preset === "custom");
  return (
    <section aria-label={t("range.title")} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label={t("range.title")} className="flex flex-wrap gap-2">
          {PRESETS.map((preset) => (
            <Button key={preset} compact variant={range.preset === preset ? "primary" : "secondary"} aria-pressed={range.preset === preset} onClick={() => { setCustom(false); onChange(presetRange(preset, today)); }}>
              {t(`range.preset_${preset}`)}
            </Button>
          ))}
          <Button compact variant={range.preset === "custom" ? "primary" : "secondary"} aria-pressed={range.preset === "custom"} aria-expanded={custom} onClick={() => setCustom((open) => !open)}>
            {t("range.custom")}
          </Button>
        </div>
        <p className="text-base text-neutral-700">{rangeLabel(range, dates)}</p>
        {children ? <div className="ml-auto flex flex-wrap items-center gap-3">{children}</div> : null}
      </div>
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
    <form onSubmit={onSubmit} noValidate aria-label={t("range.custom_form")} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <Field label={t("range.from")} error={issue ? t(`range.issue_${issue}`) : undefined}>
        {(props) => <TextInput {...props} type="date" max={today} value={from} onChange={(event) => setFrom(event.target.value)} />}
      </Field>
      <Field label={t("range.to")}>{(props) => <TextInput {...props} type="date" max={today} value={to} onChange={(event) => setTo(event.target.value)} />}</Field>
      <Button type="submit">{t("range.apply")}</Button>
    </form>
  );
}

/** A row of choices shown as pressed buttons (group by day, week or month; a kind of work). */
export function Choices<T extends string>({ label, options, value, onChange, text }: { label: string; options: readonly T[]; value: T; onChange: (value: T) => void; text: (option: T) => string }) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap items-center gap-2">
      <span className="text-sm font-medium text-neutral-700">{label}</span>
      {options.map((option) => (
        <Button key={option || "all"} compact variant={value === option ? "primary" : "secondary"} aria-pressed={value === option} onClick={() => onChange(option)}>
          {text(option)}
        </Button>
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

/** "Updated 2 min ago" (the oldest rebuild of the days read), a running rebuild, the days not built with the Rebuild button. */
export function FreshnessBar({ state, asOf }: { state: Freshness; asOf?: string | null }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const now = useNow(30_000);
  const freshness = state.freshness;
  const stamp = freshness ? freshness.as_of : (asOf ?? null);
  const ago = agoOf(stamp, now);
  const known = freshness !== null || asOf !== undefined;
  // said here, not in the notice: an inline rebuild builds the days, and the notice goes with the next look of the feed
  const [done, setDone] = useState<string | null>(null);
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        {known ? (
          <Badge tone={ago.unit === "never" ? "amber" : "neutral"}>
            <span title={stamp ? addisStamp(stamp, dates) : undefined}>{ago.unit === "never" ? t("fresh.never") : t(`fresh.${ago.unit}`, { count: ago.count })}</span>
          </Badge>
        ) : null}
        {freshness?.running ? (
          <p role="status" className="text-base text-blue-800">
            {t("fresh.running")}
          </p>
        ) : null}
        {state.error && state.loaded ? <p className="text-sm text-amber-800">{t("fresh.stale_feed")}</p> : null}
      </div>
      {freshness && freshness.stale_days.length > 0 ? <NotBuilt days={freshness.stale_days} running={freshness.running} onRebuilt={state.refresh} onDone={setDone} /> : null}
      <Status>{done}</Status>
    </div>
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
    <section aria-labelledby={headingId} className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
      <h2 id={headingId} className="text-base font-semibold text-amber-900">
        {t("stale.title")}
      </h2>
      <p className="text-base text-amber-900">
        {t("stale.body", { count: days.length, days: runs.map((run) => spanLabel(run, dates)).join(", ") })}
      </p>
      {manager ? (
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="secondary" disabled={busy || running} onClick={() => void rebuild()}>
            {busy || running ? t("stale.rebuilding") : t("stale.rebuild")}
          </Button>
          <p className="text-sm text-amber-900">{t("stale.rebuild_hint")}</p>
        </div>
      ) : (
        <p className="text-sm text-amber-900">{t("stale.ask_admin")}</p>
      )}
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
    </section>
  );
}

function pieceName(piece: RebuildPiece): string {
  return `${piece.from}|${piece.to}|${piece.sync ? 1 : 0}`;
}

// --- exports ------------------------------------------------------------------------------------------------------------------------------

/** Excel, CSV and PDF of a report over the range, in the page's language, read with the bearer token and named in the browser. */
export function ExportBar({ report, range }: { report: ReportName; range: AnalyticsRange }) {
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

  return (
    <section aria-label={t("export.title")} className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        {EXPORT_FORMATS.map((format) => (
          <Button key={format} compact variant="secondary" disabled={busy !== null} onClick={() => void download(format)}>
            {busy === format ? t("export.busy") : t(`export.${format}`)}
          </Button>
        ))}
        <p className="text-sm text-neutral-600">{t("export.hint")}</p>
      </div>
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <Status>{saved ? t("export.saved", { name: saved }) : null}</Status>
    </section>
  );
}

// --- layout pieces ------------------------------------------------------------------------------------------------------------------------

/** A headline figure: named for screen readers and tests by its label; a grey block while its query loads. */
export function Kpi({ label, value, detail, change, loading, children }: { label: string; value?: ReactNode; detail?: ReactNode; change?: ReactNode; loading?: boolean; children?: ReactNode }) {
  return (
    <div role="group" aria-label={label} aria-busy={loading || undefined} className="flex min-h-28 flex-col rounded-lg border border-neutral-200 bg-white p-4">
      <p className="text-sm font-medium text-neutral-600">{label}</p>
      {loading ? <span aria-hidden="true" className="mt-2 block h-8 w-32 animate-pulse rounded bg-neutral-200" /> : <p className="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{value}</p>}
      {!loading && detail ? <p className="mt-1 text-sm text-neutral-600">{detail}</p> : null}
      {!loading && change ? <p className="mt-1 text-sm text-neutral-700">{change}</p> : null}
      {!loading ? children : null}
    </div>
  );
}

/** Grey rows while a table's query loads (the shape of what is coming, so the page does not jump). */
export function SkeletonRows({ rows = 5, label }: { rows?: number; label: string }) {
  return (
    <div role="status" aria-label={label} className="space-y-2 py-2">
      {Array.from({ length: rows }, (_, i) => (
        <span key={i} aria-hidden="true" className="block h-6 animate-pulse rounded bg-neutral-200" style={{ width: `${92 - ((i * 13) % 30)}%` }} />
      ))}
    </div>
  );
}

/** A section of a dashboard: a titled panel. */
export function Section({ title, children, actions, className = "" }: { title: string; children: ReactNode; actions?: ReactNode; className?: string }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`rounded-lg border border-neutral-200 bg-white p-5 ${className}`}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 id={id} className="text-lg font-semibold text-neutral-900">
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
      <Button variant="secondary" onClick={onRetry}>
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

export const TH = "py-2 pr-4 font-medium";
export const TH_NUM = "py-2 pr-4 text-right font-medium";
export const TD = "py-3 pr-4 align-middle";
export const TD_NUM = "whitespace-nowrap py-3 pr-4 text-right align-middle tabular-nums";

/** A bordered table that scrolls sideways on a narrow screen. */
export function TableFrame({ children }: { children: ReactNode }) {
  return <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white px-4">{children}</div>;
}
