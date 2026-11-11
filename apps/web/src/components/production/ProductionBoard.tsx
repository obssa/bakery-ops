"use client";

import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  MouseSensor,
  pointerWithin,
  rectIntersection,
  TouchSensor,
  useDroppable,
  useSensor,
  useSensors,
  type Announcements,
  type CollisionDetection,
  type DragEndEvent,
  type DragStartEvent,
  type KeyboardCoordinateGetter,
} from "@dnd-kit/core";
import { useQuery } from "@tanstack/react-query";
import { AlertTriangle, CircleCheckBig, ClipboardCheck, ClipboardList, Flame, Plus, Store, type LucideIcon } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useCategories, useItems } from "@/lib/api/hooks";
import { uuid } from "@/lib/api/idempotency";
import { acceptRequest, confirmBatch, submitStartBatch } from "@/lib/api/production";
import type { Collection, Locale, ProductionRequest, Station } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { boardColumns, COLUMNS, createProductionFeed, ownStationId, type Column } from "@/lib/live/production";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { boardSummary, dropMove, type BoardSummary, type DropRefusal, type DropVerdict } from "@/lib/productionBoard";
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
//
// phase-6/board-drag (Ramin 2026-11-11: "two menus on top of each other"; "can we move a card from one state to the other and its status
// updates by itself"): the summary strip is gone, each step is named once, in its column head, which also carries what needs a look (the
// requests waiting too long or urgent, the batches left in the oven over a day). A card the viewer can move is dragged to the next column
// (mouse, a long press on a tablet, or its grip and the arrow keys; on a phone onto the column's tab) and the drop makes the same write as
// the card's button: Requested -> In progress accepts and starts it, In progress -> Pending confirmation (or Done today) opens the Done form,
// Pending confirmation -> Done today confirms it, Pending confirmation -> In progress opens Send back. While a card is dragged, a column it
// cannot go to is dimmed and says why (lib/productionBoard.ts dropMove); the card moves at once and goes back with the reason if the
// server refuses. The board reads itself again after every move, so the status updates by itself.

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
  const [opened, setOpened] = useState<{ id: number; focus?: "finish" | "send_back" } | null>(() => {
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

  // ---- drag and drop (phase-6/board-drag) ----
  const [dragged, setDragged] = useState<ProductionRequest | null>(null);
  const [moveError, setMoveError] = useState<string | null>(null);
  // a card moved before the server answered: shown in its new column until the board's next read carries a newer version of it
  const [moved, setMoved] = useState<Map<number, { column: Column; version: number }>>(() => new Map());
  useEffect(() => {
    const requests = snapshot?.state.requests;
    if (!requests || moved.size === 0) return;
    const next = new Map(moved);
    for (const [id, move] of moved) {
      const now = requests.find((request) => request.id === id);
      if (!now || now.version !== move.version) next.delete(id);
    }
    if (next.size !== moved.size) setMoved(next);
  }, [snapshot, moved]);
  const shown = useMemo(() => (columns ? withMoves(columns, moved) : null), [columns, moved]);
  const movable = useCallback((request: ProductionRequest) => COLUMNS.some((column) => (dropMove(me, request, column) as { move?: string } | null)?.move !== undefined), [me]);
  const verdicts = useMemo(() => (dragged ? Object.fromEntries(COLUMNS.map((column) => [column, dropMove(me, dragged, column)])) as Record<Column, DropVerdict> : null), [dragged, me]);
  const sensors = useSensors(
    useSensor(MouseSensor, { activationConstraint: { distance: 8 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 250, tolerance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: columnJump }),
  );
  const nameOf = useCallback((request: ProductionRequest) => (locale === "am" ? request.product.name_am : request.product.name_en), [locale]);
  const requestOf = useCallback((id: unknown) => snapshot?.state.requests.find((request) => `request-${request.id}` === id) ?? null, [snapshot]);
  const columnName = useCallback((id: unknown) => {
    const column = targetColumn(id);
    return column ? t(`col_${column}` as "col_requested") : "";
  }, [t]);
  const announcements: Announcements = {
    onDragStart: ({ active }) => {
      const request = requestOf(active.id);
      return request ? t("drag_picked", { name: nameOf(request) }) : undefined;
    },
    onDragOver: ({ active, over }) => {
      const request = requestOf(active.id);
      return request && over ? t("drag_over", { name: nameOf(request), column: columnName(over.id) }) : undefined;
    },
    onDragEnd: ({ active, over }) => {
      const request = requestOf(active.id);
      return request && over ? t("drag_dropped", { name: nameOf(request), column: columnName(over.id) }) : undefined;
    },
    onDragCancel: ({ active }) => {
      const request = requestOf(active.id);
      return request ? t("drag_cancelled", { name: nameOf(request) }) : undefined;
    },
  };

  function onDragStart(event: DragStartEvent) {
    setMoveError(null);
    setDragged(requestOf(event.active.id));
  }

  function onDragEnd(event: DragEndEvent) {
    const request = dragged;
    setDragged(null);
    const target = event.over ? targetColumn(event.over.id) : null;
    if (!request || !target) return;
    const verdict = dropMove(me, request, target);
    if (!verdict) return;
    if ("refused" in verdict) {
      setMoveError(refusalText(verdict.refused));
      return;
    }
    void makeMove(request, verdict.move, target);
  }

  function refusalText(refusal: DropRefusal): string {
    return "name" in refusal ? t(refusal.reason, { name: refusal.name }) : t(refusal.reason);
  }

  async function makeMove(request: ProductionRequest, move: "accept_start" | "start" | "finish" | "confirm" | "send_back", target: Column) {
    // the two moves that need words open the request card on their form; the card moves when the form is sent
    if (move === "finish") return open(request, "finish");
    if (move === "send_back") return setOpened({ id: request.id, focus: "send_back" });
    setMoved((current) => new Map(current).set(request.id, { column: target, version: request.version }));
    try {
      if (move === "accept_start") {
        await acceptRequest(request.id, uuid());
        await submitStartBatch(request.id, uuid(), uuid());
      } else if (move === "start") {
        await submitStartBatch(request.id, uuid(), uuid());
      } else if (request.batch) {
        await confirmBatch(request.batch.id, uuid());
      }
    } catch (failure) {
      setMoved((current) => {
        const next = new Map(current);
        next.delete(request.id);
        return next;
      });
      setMoveError(t("move_failed", { name: nameOf(request), reason: errorText.message(failure) }));
    } finally {
      refresh();
    }
  }

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
          {moveError ? (
            <Alert>
              <div className="flex items-start justify-between gap-3">
                <span>{moveError}</span>
                <button type="button" onClick={() => setMoveError(null)} className={`shrink-0 rounded-md text-sm font-semibold underline ${focusRing}`}>
                  {t("close")}
                </button>
              </div>
            </Alert>
          ) : null}
          <DndContext
            sensors={sensors}
            collisionDetection={collision}
            accessibility={{ announcements, screenReaderInstructions: { draggable: t("drag_hint") } }}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onDragCancel={() => setDragged(null)}
          >
            {summary ? <ColumnTabs summary={summary} tab={tab} onTab={setTab} verdicts={verdicts} /> : null}
            <div role="group" aria-label={t("board")} className="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-4">
              {COLUMNS.map((column) => (
                <BoardColumn
                  key={column}
                  column={column}
                  twin={twins?.columns[column]}
                  count={(shown ?? columns)[column].length}
                  phoneHidden={column !== tab}
                  attention={summary ? attentionOf(column, summary) : null}
                  verdict={verdicts?.[column] ?? null}
                >
                  {(shown ?? columns)[column].map((request) => (
                    <RequestCard
                      key={request.id}
                      request={request}
                      selected={request.id === opened?.id}
                      image={images.get(request.product.id) ?? null}
                      category={categoryOf.get(request.product.id) ?? null}
                      onOpen={open}
                      onChanged={refresh}
                      draggable={movable(request)}
                      lifted={dragged?.id === request.id}
                    />
                  ))}
                </BoardColumn>
              ))}
            </div>
            <DragOverlay dropAnimation={null}>{dragged ? <CardLift request={dragged} locale={locale} /> : null}</DragOverlay>
          </DndContext>
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

/** What a column head flags in amber: the requests waiting too long (or the urgent ones), the batches left in the oven over a day. */
function attentionOf(column: Column, summary: BoardSummary): { key: "sum_waiting" | "sum_urgent" | "sum_stale"; count: number } | null {
  if (column === "requested" && summary.waiting > 0) return { key: "sum_waiting", count: summary.waiting };
  if (column === "requested" && summary.urgent > 0) return { key: "sum_urgent", count: summary.urgent };
  if (column === "in_progress" && summary.stale > 0) return { key: "sum_stale", count: summary.stale };
  return null;
}

/** The board's columns with the cards moved before the server answered put in their new column. */
function withMoves(columns: Record<Column, ProductionRequest[]>, moved: Map<number, { column: Column; version: number }>): Record<Column, ProductionRequest[]> {
  if (moved.size === 0) return columns;
  const out: Record<Column, ProductionRequest[]> = { requested: [], in_progress: [], pending_confirmation: [], done_today: [] };
  for (const column of COLUMNS) {
    for (const request of columns[column]) out[moved.get(request.id)?.column ?? column].push(request);
  }
  return out;
}

/** The column a droppable stands for: a column itself ("requested") or its tab on a phone ("tab:requested"). */
function targetColumn(id: unknown): Column | null {
  const name = String(id).replace(/^tab:/, "");
  return (COLUMNS as readonly string[]).includes(name) ? (name as Column) : null;
}

/** Drops onto what is under the pointer, else what the dragged card overlaps most (a keyboard move). */
const collision: CollisionDetection = (args) => {
  const within = pointerWithin(args);
  return within.length > 0 ? within : rectIntersection(args);
};

/**
 * The keyboard moves a lifted card a whole column at a time: the left and right arrows jump to the centre of the next column (or tab, on a
 * phone) on that side, so a drop needs two key presses, not twenty.
 */
const columnJump: KeyboardCoordinateGetter = (event, { currentCoordinates, context }) => {
  if (event.code !== "ArrowRight" && event.code !== "ArrowLeft") return undefined;
  event.preventDefault();
  const rect = context.collisionRect;
  if (!rect) return undefined;
  const cx = rect.left + rect.width / 2;
  const cy = rect.top + rect.height / 2;
  const targets: { x: number; y: number }[] = [];
  for (const [, box] of context.droppableRects) {
    if (box.width > 0 && box.height > 0) targets.push({ x: box.left + box.width / 2, y: box.top + Math.min(box.height / 2, 80) });
  }
  targets.sort((a, b) => a.x - b.x);
  const next = event.code === "ArrowRight" ? targets.find((target) => target.x > cx + 1) : [...targets].reverse().find((target) => target.x < cx - 1);
  return next ? { x: currentCoordinates.x + (next.x - cx), y: currentCoordinates.y + (next.y - cy) } : currentCoordinates;
};

/** What follows the pointer while a card is dragged: its name and quantity, raised. */
function CardLift({ request, locale }: { request: ProductionRequest; locale: Locale }) {
  return (
    <div className="flex w-72 max-w-[80vw] cursor-grabbing items-center justify-between gap-3 rounded-2xl border border-ink-900 bg-white px-4 py-3 shadow-float">
      <span className="truncate text-base font-semibold text-neutral-900">{locale === "am" ? request.product.name_am : request.product.name_en}</span>
      <span className="shrink-0 text-xl font-bold tabular-nums text-neutral-900">{trimDecimal(request.requested_qty)}</span>
    </div>
  );
}

/** The columns as tabs on a phone (under md): four equal tabs, each its short name over its count; the chosen one is charcoal. */
function ColumnTabs({ summary, tab, onTab, verdicts }: { summary: BoardSummary; tab: Column; onTab: (column: Column) => void; verdicts: Record<Column, DropVerdict> | null }) {
  const t = useTranslations("production");
  return (
    <div role="tablist" aria-label={t("tabs")} className="grid grid-cols-4 gap-1 rounded-2xl border border-neutral-200 bg-white p-1 md:hidden">
      {COLUMNS.map((column) => (
        <ColumnTab key={column} column={column} chosen={column === tab} summary={summary} onTab={onTab} verdict={verdicts?.[column] ?? null} />
      ))}
    </div>
  );
}

/** One tab; while a card is dragged it is also where the card is dropped on a phone (dimmed when the card cannot go there). */
function ColumnTab({ column, chosen, summary, onTab, verdict }: { column: Column; chosen: boolean; summary: BoardSummary; onTab: (column: Column) => void; verdict: DropVerdict }) {
  const t = useTranslations("production");
  const { setNodeRef, isOver } = useDroppable({ id: `tab:${column}` });
  const warn = attentionOf(column, summary) !== null;
  const ok = verdict !== null && "move" in verdict;
  const refused = verdict !== null && "refused" in verdict;
  return (
    <button
      ref={setNodeRef}
      type="button"
      role="tab"
      aria-selected={chosen}
      onClick={() => onTab(column)}
      className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-1 motion-safe:transition-colors motion-safe:duration-150 ${chosen ? "bg-ink-900 text-white" : "text-neutral-700 hover:bg-neutral-100"} ${ok ? "outline-2 outline-dashed outline-ink-900" : ""} ${ok && isOver && !chosen ? "bg-ink-100" : ""} ${refused ? "opacity-40" : ""} ${focusRing}`}
    >
      <span className="flex items-center gap-1 text-lg font-bold leading-none tabular-nums">
        {summary.counts[column]}
        {warn ? <AlertTriangle className={`size-3.5 ${chosen ? "text-amber-300" : "text-amber-700"}`} strokeWidth={ICON_STROKE} aria-hidden="true" /> : null}
      </span>
      <span className="max-w-full truncate text-xs font-semibold">{t(`tab_${column}` as "tab_requested")}</span>
    </button>
  );
}

/**
 * One column of the board under its tinted head: the icon, the step ("Step 1", "Today" over Done today), its name (the Amharic name under
 * it on the English page) with its "?" (who acts there and what moves a card on, Ramin 2026-11-11), the count and a line saying what
 * happens there; then its cards, or an empty state saying what lands in it. On a phone only the chosen tab's column shows.
 */
function BoardColumn({
  column,
  twin,
  count,
  phoneHidden,
  attention,
  verdict,
  children,
}: {
  column: Column;
  twin?: string;
  count: number;
  phoneHidden: boolean;
  attention: { key: "sum_waiting" | "sum_urgent" | "sum_stale"; count: number } | null;
  /** while a card is dragged: what dropping it here does, or why it cannot come here (null: its own column, or nothing dragged) */
  verdict: DropVerdict;
  children: ReactNode;
}) {
  const t = useTranslations("production");
  const title = t(`col_${column}` as "col_requested");
  const look = COLUMN_LOOK[column];
  const Icon = look.icon;
  const step = column === "done_today" ? t("step_done") : t("step", { n: COLUMNS.indexOf(column) + 1 });
  const { setNodeRef, isOver } = useDroppable({ id: column });
  const ok = verdict !== null && "move" in verdict;
  const refusal = verdict !== null && "refused" in verdict ? verdict.refused : null;
  return (
    <section
      ref={setNodeRef}
      aria-label={title}
      className={`min-w-0 flex-col gap-3 rounded-2xl motion-safe:transition-opacity motion-safe:duration-150 ${phoneHidden ? "hidden md:flex" : "flex"} ${ok ? `outline-2 outline-offset-4 outline-dashed ${isOver ? "outline-ink-900" : "outline-ink-300"}` : ""} ${refusal ? "opacity-50" : ""}`}
    >
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
        {attention ? (
          <span className="inline-flex items-center gap-1.5 self-start rounded-full bg-amber-100 px-2.5 py-0.5 text-[13px] font-semibold text-amber-900">
            <AlertTriangle className="size-3.5 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t(attention.key, { count: attention.count })}
          </span>
        ) : null}
      </div>
      {ok ? (
        <p className={`rounded-xl border border-dashed px-3 py-2 text-center text-[13px] font-semibold ${isOver ? "border-ink-900 bg-ink-50 text-ink-900" : "border-ink-300 text-neutral-700"}`}>{t("drop_here")}</p>
      ) : null}
      {refusal ? <p className="rounded-xl bg-neutral-100 px-3 py-2 text-[13px] font-medium text-neutral-800">{"name" in refusal ? t(refusal.reason, { name: refusal.name }) : t(refusal.reason)}</p> : null}
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
