"use client";

import { useQuery } from "@tanstack/react-query";
import { AlertTriangle, CircleCheckBig, ClipboardCheck, ClipboardList, Flame, Plus, Store, type LucideIcon } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useCategories, useItems } from "@/lib/api/hooks";
import type { Collection, Locale, ProductionRequest, Station } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { boardColumns, COLUMNS, createProductionFeed, ownStationId, type Column } from "@/lib/live/production";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { boardSummary, type BoardSummary } from "@/lib/productionBoard";
import { useProductImages } from "@/lib/productImages";
import { authed, can, useSession } from "@/lib/session";
import { useStationHeader } from "@/lib/topBar";
import { useNow } from "@/lib/useNow";
import { ICON_STROKE } from "../icons";
import { StationHeaderActions } from "../StationHeaderActions";
import { focusRing } from "../styles";
import { DisplayCasePanel } from "../till/DisplayCasePanel";
import { Alert, Button, Drawer, HelpTip, SegmentedControl, Select } from "../ui";
import { ShiftBar } from "../shifts/ShiftBar";
import { useShiftStation } from "../shifts/useShiftStation";
import { NewBatchDialog } from "./NewBatchDialog";
import { RequestCard } from "./RequestCard";
import { RequestDetail } from "./RequestDetail";
import type { ProductionTwins } from "./twins";

// The production board (/production, docs/architecture.md section 6; fresh-counter.md 3.5, the Production board, step 6.3c). The station
// top bar is the board's header (lib/topBar.ts): the board's name with its Amharic name beside it on the English page, the station and its
// shift under it, and "Display case stock" and "New batch" among the bar's buttons. Under the bar the shift is one strip (ShiftBar's
// `strip`, with the board's station filter at its end), then four columns, Requested, In progress, Pending confirmation and Done today,
// each a region named by its column with its count, holding the cards. Polled every 5 s (30 s while hidden) through a LiveFeed over GET
// /production/requests?view=board with ETag / 304. Accepted cards sit in Requested with their baker shown. A baker who works at one
// production station sees that station first; everyone can widen the board to all stations. A card's name opens the request card in a
// drawer from the right, and ?request=<id> (a notification link) opens it at once. The product thumbs are the catalog's photos
// (useProductImages, phase-6 plan 10.2 Q7); offline they are the placeholders.
//
// phase-6/production-board (Ramin 2026-11-10: the board was too basic): a summary strip over the columns (each column's count with its icon,
// the urgent requests waiting and the batches left in the oven over a day), the station filter as a segmented control when there are five
// stations or fewer, each column under a tinted head with its icon, its step and a line saying what happens there, and an empty column
// saying what lands in it. On a phone (under md) the columns are tabs with their counts, one column at a time, full width.

const PRODUCTION_KINDS = ["cake_room", "bakery"] as const;

/** A segmented station filter up to this many stations; a select beyond. */
const SEGMENTED_STATIONS = 5;

/** Each column's look: its icon and its tint (the head, the icon tile, the count). Requested is ink, baking blue, waiting amber, done green-teal. */
const COLUMN_LOOK: Record<Column, { icon: LucideIcon; head: string; ink: string; count: string }> = {
  requested: { icon: ClipboardList, head: "border-ink-200 bg-ink-50", ink: "text-ink-800", count: "text-ink-900" },
  in_progress: { icon: Flame, head: "border-blue-200 bg-blue-50", ink: "text-blue-700", count: "text-blue-800" },
  pending_confirmation: { icon: ClipboardCheck, head: "border-amber-200 bg-amber-50", ink: "text-amber-700", count: "text-amber-900" },
  done_today: { icon: CircleCheckBig, head: "border-positive-200 bg-positive-50", ink: "text-positive-700", count: "text-positive-800" },
};

export function ProductionBoard({ twins }: { twins?: ProductionTwins }) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const params = useSearchParams();

  const shiftStation = useShiftStation({ kinds: PRODUCTION_KINDS });
  const feed = useMemo(() => createProductionFeed(), []);
  const snapshot = useLiveFeed(feed);
  const refresh = useCallback(() => feed.refresh(), [feed]);

  const [stationId, setStationId] = useState<number | "all">(() => ownStationId(me) ?? "all");
  const [opened, setOpened] = useState<{ id: number; focus?: "finish" } | null>(() => {
    const requested = Number(params.get("request"));
    return Number.isInteger(requested) && requested > 0 ? { id: requested } : null;
  });
  const [sheet, setSheet] = useState<"new_batch" | "display_case" | null>(null);

  // a notification link to this page while it is open names another card
  const linked = Number(params.get("request"));
  useEffect(() => {
    if (Number.isInteger(linked) && linked > 0) setOpened({ id: linked });
  }, [linked]);

  // the product thumbs and, for a product without a photo, its category's placeholder icon
  const images = useProductImages();
  const products = useItems("product");
  const categories = useCategories();
  const categoryOf = useMemo(() => {
    const names = new Map((categories.data ?? []).map((c) => [c.id, c.name_en]));
    const byItem = new Map<number, string>();
    for (const item of products.data ?? []) if (item.category_id !== null && names.has(item.category_id)) byItem.set(item.id, names.get(item.category_id) ?? "");
    return byItem;
  }, [categories.data, products.data]);

  // the stations to filter by: the ones with requests on the board plus the person's own
  const stations = useQuery({
    queryKey: ["stations-of-board"],
    queryFn: async () => (await authed<Collection<Station>>("/stations")).data,
    enabled: !!me && me.permissions.includes("people.manage"),
  });
  const choices = useMemo(() => {
    const found = new Map<number, { id: number; name_en: string; name_am: string }>();
    for (const request of snapshot?.state.requests ?? []) found.set(request.station.id, request.station);
    for (const station of me?.user.stations ?? []) if (station.code === "cake_room" || station.code === "bakery") found.set(station.station_id, { id: station.station_id, name_en: station.name_en, name_am: station.name_am });
    for (const station of stations.data ?? []) if (station.code === "cake_room" || station.code === "bakery") found.set(station.id, station);
    return [...found.values()].sort((a, b) => a.id - b.id);
  }, [snapshot, me, stations.data]);

  const columns = useMemo(() => (snapshot ? boardColumns(snapshot.state, stationId) : null), [snapshot, stationId]);
  const total = columns ? COLUMNS.reduce((n, column) => n + columns[column].length, 0) : 0;
  const now = useNow(60_000);
  const summary = useMemo(() => (columns ? boardSummary(columns, now) : null), [columns, now]);
  // the column a phone shows (the columns are tabs under md)
  const [tab, setTab] = useState<Column>("requested");
  const openedRequest = opened !== null ? snapshot?.state.requests.find((request) => request.id === opened.id) : undefined;
  const open = useCallback((request: ProductionRequest, focus?: "finish") => setOpened((current) => (current?.id === request.id && !focus ? null : { id: request.id, focus })), []);
  const close = useCallback(() => setOpened(null), []);

  // the station top bar names the board (the Production board's header): its name, its Amharic name on the English page, and the station
  // whose shift the strip follows with that shift ("Bakery oven · Morning shift")
  const station = shiftStation.station;
  const template = shiftStation.shift?.template;
  const subtitle = station
    ? [locale === "am" ? station.name_am : station.name_en, template ? (locale === "am" ? template.name_am : template.name_en) : null].filter(Boolean).join(" · ")
    : undefined;
  useStationHeader({ title: t("title"), titleAm: twins?.title, subtitle });

  // one station picker: choosing a station on the board also points the shift strip at it, so the strip draws no picker of its own
  const { choices: shiftChoices, choose } = shiftStation;
  useEffect(() => {
    if (stationId !== "all" && shiftChoices.some((choice) => choice.station_id === stationId)) choose(stationId);
  }, [stationId, shiftChoices, choose]);
  const strip = { ...shiftStation, choices: [] };

  return (
    <div className="flex flex-col gap-5">
      {/* the page's heading; the station top bar shows the name */}
      <h1 className="sr-only">{t("title")}</h1>
      {/* the shift is a menu in the station top bar (its state as a chip; the panel holds the region "Shift" with its words and actions, and
          opens on a tap). While the shift needs someone its strip sits under the bar and pushes the columns down; otherwise they start right
          under the bar */}
      <ShiftBar model={strip} look="menu" />

      {/* the board's buttons in the station top bar, after the shift's chip and before the sync chip. "Display case stock" shows its
          short words; its name keeps the whole (the drawer it opens has that name) */}
      <StationHeaderActions>
        {can(me, "inventory.view") ? (
          <Button type="button" variant="secondary" size="md" onClick={() => setSheet("display_case")}>
            <Store className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
            <span aria-hidden="true">{t("display_case_short")}</span>
            <span className="sr-only">{t("display_case")}</span>
          </Button>
        ) : null}
        {can(me, "production.request") ? (
          <Button type="button" size="md" onClick={() => setSheet("new_batch")}>
            <Plus className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t("new_batch")}
          </Button>
        ) : null}
      </StationHeaderActions>

      {/* the board's station filter (only when there is a choice): a segmented control for a few stations, a select for more */}
      {choices.length > 1 && choices.length <= SEGMENTED_STATIONS ? (
        <SegmentedControl
          label={t("station")}
          tone="station"
          className="self-start"
          value={String(stationId)}
          onChange={(value) => setStationId(value === "all" ? "all" : Number(value))}
          options={[{ value: "all", label: t("all_stations") }, ...choices.map((choice) => ({ value: String(choice.id), label: locale === "am" ? choice.name_am : choice.name_en }))]}
        />
      ) : null}
      {choices.length > SEGMENTED_STATIONS ? (
        <div className="flex">
          <label htmlFor="production-station" className="sr-only">
            {t("station")}
          </label>
          <div className="w-56">
            <Select id="production-station" controlSize="md" value={String(stationId)} onChange={(event) => setStationId(event.target.value === "all" ? "all" : Number(event.target.value))}>
              <option value="all">{t("all_stations")}</option>
              {choices.map((choice) => (
                <option key={choice.id} value={choice.id}>
                  {locale === "am" ? choice.name_am : choice.name_en}
                </option>
              ))}
            </Select>
          </div>
        </div>
      ) : null}

      {snapshot?.error && snapshot.loaded ? (
        <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {t("stale")}
        </p>
      ) : null}

      {!snapshot?.loaded || !columns ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("loading")}
          </p>
        )
      ) : (
        <>
          {/* said once for a screen reader when the board is empty; each column also shows that it has nothing */}
          <p role="status" className={total === 0 ? "text-[15px] text-neutral-600" : "sr-only"}>
            {total === 0 ? t("empty") : ""}
          </p>
          {summary ? <SummaryStrip summary={summary} /> : null}
          {summary ? <ColumnTabs summary={summary} tab={tab} onTab={setTab} /> : null}
          <div role="group" aria-label={t("board")} className="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-4">
            {COLUMNS.map((column) => (
              <BoardColumn key={column} column={column} twin={twins?.columns[column]} count={columns[column].length} phoneHidden={column !== tab}>
                {columns[column].map((request) => (
                  <RequestCard
                    key={request.id}
                    request={request}
                    selected={request.id === opened?.id}
                    image={images.get(request.product.id) ?? null}
                    category={categoryOf.get(request.product.id) ?? null}
                    onOpen={open}
                    onChanged={refresh}
                  />
                ))}
              </BoardColumn>
            ))}
          </div>
        </>
      )}

      {openedRequest ? (
        <CardPanel key={openedRequest.id} onClose={close}>
          <RequestDetail
            summary={openedRequest}
            image={images.get(openedRequest.product.id) ?? null}
            category={categoryOf.get(openedRequest.product.id) ?? null}
            focus={opened?.focus}
            onClose={close}
            onChanged={refresh}
          />
        </CardPanel>
      ) : null}

      {sheet === "new_batch" ? <NewBatchDialog stationId={stationId} onClose={() => setSheet(null)} onAsked={refresh} /> : null}
      {sheet === "display_case" ? (
        <Drawer label={t("display_case")} onClose={() => setSheet(null)}>
          <div className="flex justify-end">
            <Button type="button" variant="secondary" size="md" onClick={() => setSheet(null)}>
              {t("close")}
            </Button>
          </div>
          <DisplayCasePanel />
        </Drawer>
      ) : null}
    </div>
  );
}

/**
 * The request card beside the board: a 480 px panel from the right, under the station top bar, over the right-hand columns, with no scrim,
 * so the bar (the bell, the board's buttons) and the cards on the left stay in reach while it is open. The panel takes the focus when it
 * opens; Escape or the card's own "Close card" closes it. The card inside is the region "Request card: <product>". On a phone it covers
 * the window.
 */
function CardPanel({ onClose, children }: { onClose: () => void; children: ReactNode }) {
  const panel = useRef<HTMLDivElement>(null);
  useEffect(() => {
    panel.current?.focus();
  }, []);
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);
  return (
    <div
      ref={panel}
      tabIndex={-1}
      className="fixed inset-0 z-20 overflow-y-auto border-l border-neutral-200 bg-white p-6 shadow-float outline-none motion-safe:animate-drawer-in md:left-auto md:top-17 md:w-[480px]"
    >
      {children}
    </div>
  );
}

/** Does the column hold something that needs a look: an urgent request waiting, or a batch left in the oven over a day? */
function needsLook(column: Column, summary: BoardSummary): boolean {
  return (column === "requested" && summary.urgent > 0) || (column === "in_progress" && summary.stale > 0);
}

/**
 * The board at a glance, over the columns from md up: one tile per column with its icon, its count and a line, the urgent requests and the
 * batches left in the oven over a day in amber with a warning sign. A phone shows the counts on its column tabs instead.
 */
function SummaryStrip({ summary }: { summary: BoardSummary }) {
  const t = useTranslations("production");
  return (
    <ul aria-label={t("summary")} className="hidden gap-3 md:grid md:grid-cols-2 lg:grid-cols-4">
      {COLUMNS.map((column) => {
        const look = COLUMN_LOOK[column];
        const Icon = look.icon;
        const warn = needsLook(column, summary);
        const note =
          column === "requested" && warn
            ? t("sum_urgent", { count: summary.urgent })
            : column === "in_progress" && warn
              ? t("sum_stale", { count: summary.stale })
              : t(`sum_${column}` as "sum_requested");
        return (
          <li key={column} className="flex items-center gap-3.5 rounded-2xl border border-neutral-200 bg-white px-4 py-3.5">
            <span aria-hidden="true" className={`inline-flex size-11 shrink-0 items-center justify-center rounded-xl border ${look.head}`}>
              <Icon className={`size-[22px] ${look.ink}`} strokeWidth={ICON_STROKE} />
            </span>
            <span className="flex min-w-0 flex-col">
              <span className="flex items-baseline gap-2">
                <span className="text-[26px] font-bold leading-none tabular-nums text-neutral-900">{summary.counts[column]}</span>
                <span className="truncate text-[15px] font-semibold text-neutral-900">{t(`col_${column}` as "col_requested")}</span>
              </span>
              <span className={`mt-1 flex items-center gap-1 text-[13px] ${warn ? "font-semibold text-amber-800" : "text-neutral-600"}`}>
                {warn ? <AlertTriangle className="size-3.5 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" /> : null}
                <span className="truncate">{note}</span>
              </span>
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/** The columns as tabs on a phone (under md): four equal tabs, each its short name over its count; the chosen one is charcoal. */
function ColumnTabs({ summary, tab, onTab }: { summary: BoardSummary; tab: Column; onTab: (column: Column) => void }) {
  const t = useTranslations("production");
  return (
    <div role="tablist" aria-label={t("tabs")} className="grid grid-cols-4 gap-1 rounded-2xl border border-neutral-200 bg-white p-1 md:hidden">
      {COLUMNS.map((column) => {
        const chosen = column === tab;
        const warn = needsLook(column, summary);
        return (
          <button
            key={column}
            type="button"
            role="tab"
            aria-selected={chosen}
            onClick={() => onTab(column)}
            className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-1 motion-safe:transition-colors motion-safe:duration-150 ${chosen ? "bg-ink-900 text-white" : "text-neutral-700 hover:bg-neutral-100"} ${focusRing}`}
          >
            <span className="flex items-center gap-1 text-lg font-bold leading-none tabular-nums">
              {summary.counts[column]}
              {warn ? <AlertTriangle className={`size-3.5 ${chosen ? "text-amber-300" : "text-amber-700"}`} strokeWidth={ICON_STROKE} aria-hidden="true" /> : null}
            </span>
            <span className="max-w-full truncate text-xs font-semibold">{t(`tab_${column}` as "tab_requested")}</span>
          </button>
        );
      })}
    </div>
  );
}

/**
 * One column of the board under its tinted head: the icon, the step ("Step 1", "Today" over Done today), its name (the Amharic name under
 * it on the English page) with its "?" (who acts there and what moves a card on, Ramin 2026-11-11), the count and a line saying what
 * happens there; then its cards, or an empty state saying what lands in it. On a phone only the chosen tab's column shows.
 */
function BoardColumn({ column, twin, count, phoneHidden, children }: { column: Column; twin?: string; count: number; phoneHidden: boolean; children: ReactNode }) {
  const t = useTranslations("production");
  const title = t(`col_${column}` as "col_requested");
  const look = COLUMN_LOOK[column];
  const Icon = look.icon;
  const step = column === "done_today" ? t("step_done") : t("step", { n: COLUMNS.indexOf(column) + 1 });
  return (
    <section aria-label={title} className={`min-w-0 flex-col gap-3 ${phoneHidden ? "hidden md:flex" : "flex"}`}>
      <div className={`flex flex-col gap-2 rounded-2xl border px-4 py-3 ${look.head}`}>
        <div className="flex items-center gap-3">
          <span aria-hidden="true" className={`inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-white ${look.ink}`}>
            <Icon className="size-5" strokeWidth={ICON_STROKE} />
          </span>
          <div className="flex min-w-0 flex-1 flex-col">
            <span className="text-xs font-semibold uppercase tracking-wide text-neutral-600">{step}</span>
            {/* the "?" beside the heading, not in it: the heading's name stays the column's name */}
            <span className="flex items-center gap-2">
              <h2 className="text-base font-semibold leading-tight text-neutral-900">{title}</h2>
              <HelpTip label={t("tip_label", { column: title })}>{t(`tip_${column}` as "tip_requested")}</HelpTip>
            </span>
            {twin ? (
              <span lang="am" className="text-[13px] font-medium text-neutral-600">
                {twin}
              </span>
            ) : null}
          </div>
          <span className={`inline-flex h-8 min-w-8 shrink-0 items-center justify-center rounded-full bg-white px-2.5 text-[15px] font-bold tabular-nums ${look.count}`}>{count}</span>
        </div>
        <p className="text-[13px] leading-snug text-neutral-700">{t(`help_${column}` as "help_requested")}</p>
      </div>
      {count === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-neutral-300 px-5 py-7 text-center">
          <Icon className="size-7 text-neutral-400" strokeWidth={ICON_STROKE} aria-hidden="true" />
          <p className="text-[13px] text-neutral-600">{t(`empty_${column}` as "empty_requested")}</p>
        </div>
      ) : (
        children
      )}
    </section>
  );
}
