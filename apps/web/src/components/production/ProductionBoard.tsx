"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Store } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useCategories, useItems } from "@/lib/api/hooks";
import type { Collection, Locale, ProductionRequest, Station } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { boardColumns, COLUMNS, createProductionFeed, ownStationId, type Column } from "@/lib/live/production";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useProductImages } from "@/lib/productImages";
import { authed, can, useSession } from "@/lib/session";
import { useStationHeader } from "@/lib/topBar";
import { useNow } from "@/lib/useNow";
import { ICON_STROKE } from "../icons";
import { StationHeaderActions } from "../StationHeaderActions";
import { DisplayCasePanel } from "../till/DisplayCasePanel";
import { Alert, Button, Drawer, Select } from "../ui";
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

const PRODUCTION_KINDS = ["cake_room", "bakery"] as const;

export function ProductionBoard({ twins }: { twins?: ProductionTwins }) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const params = useSearchParams();
  const now = useNow(30_000);

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
      {/* the board's buttons in the station top bar, before the sync chip */}
      <StationHeaderActions>
        {can(me, "inventory.view") ? (
          <Button type="button" variant="secondary" size="md" onClick={() => setSheet("display_case")}>
            <Store className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t("display_case")}
          </Button>
        ) : null}
        {can(me, "production.request") ? (
          <Button type="button" size="md" onClick={() => setSheet("new_batch")}>
            <Plus className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
            {t("new_batch")}
          </Button>
        ) : null}
      </StationHeaderActions>

      {/* the shift as one strip (the state, the shift, who is on it, its actions), with the board's station filter at its end */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div className="min-w-0 flex-1">
          <ShiftBar model={strip} look="strip" />
        </div>
        {choices.length > 1 ? (
          <div className="w-52 shrink-0">
            <label htmlFor="production-station" className="sr-only">
              {t("station")}
            </label>
            <Select id="production-station" controlSize="md" value={String(stationId)} onChange={(event) => setStationId(event.target.value === "all" ? "all" : Number(event.target.value))}>
              <option value="all">{t("all_stations")}</option>
              {choices.map((choice) => (
                <option key={choice.id} value={choice.id}>
                  {locale === "am" ? choice.name_am : choice.name_en}
                </option>
              ))}
            </Select>
          </div>
        ) : null}
      </div>

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
          <div role="group" aria-label={t("board")} className="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-4">
            {COLUMNS.map((column) => (
              <BoardColumn key={column} column={column} twin={twins?.columns[column]} count={columns[column].length}>
                {columns[column].map((request) => (
                  <RequestCard
                    key={request.id}
                    request={request}
                    selected={request.id === opened?.id}
                    image={images.get(request.product.id) ?? null}
                    category={categoryOf.get(request.product.id) ?? null}
                    now={now}
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

/** One column of the board: its name (the Amharic name under it on the English page) and a count pill, then its cards or "Nothing here". */
function BoardColumn({ column, twin, count, children }: { column: Column; twin?: string; count: number; children: ReactNode }) {
  const t = useTranslations("production");
  const title = t(`col_${column}` as "col_requested");
  return (
    <section aria-label={title} className="flex min-w-0 flex-col gap-3">
      <div className="flex items-center justify-between gap-2 px-1">
        <div className="flex min-w-0 flex-col">
          <h2 className="text-base font-semibold text-neutral-900">{title}</h2>
          {twin ? (
            <span lang="am" className="text-[13px] font-medium text-neutral-600">
              {twin}
            </span>
          ) : null}
        </div>
        <span className="inline-flex h-[30px] min-w-[30px] shrink-0 items-center justify-center rounded-full border border-neutral-200 bg-white px-2.5 text-sm font-semibold tabular-nums text-neutral-900">
          {count}
        </span>
      </div>
      {count === 0 ? <p className="rounded-2xl border border-dashed border-neutral-300 px-4 py-5 text-center text-[13px] text-neutral-600">{t("col_empty")}</p> : children}
    </section>
  );
}
