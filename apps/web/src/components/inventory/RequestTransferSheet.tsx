"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useRef, useState } from "react";
import { groupDigits } from "@/lib/analytics";
import { useItems } from "@/lib/api/hooks";
import { useLocations, useStock, type LocationRow } from "@/lib/api/inventory";
import { requestTransfer } from "@/lib/api/production";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import type { Item, Locale, Me, NewTransfer } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { Alert, Button, Field, Modal, Select, TextInput } from "../ui";

// The one sheet that asks for stock to be moved. Two ways in:
//
// - A fixed item (`subject`): from the kitchen display's stock strip (a chip's button) and from the production card (a short recipe line,
//   which also names the request). The item and the place that asks are known; the person types how much.
// - A picked item (`pick`, phase-6/stock-requests; Ramin: the strip shows only 8 ingredients and there was no way to ask for anything else,
//   and no way to start a transfer in the admin): Admin > Inventory > Transfers' "New transfer", the stations' "Request stock" at the end of the
//   kitchen and bar strip, and the production board's "Request stock". The person finds the item (every active item kept in stock, ingredients
//   and products, whether the place that asks holds it yet or not; the ones the source holds first, each with what the source has available),
//   the source (the branch store unless they choose another; never the cash drawer) and, unless the caller fixes it to a station's own place,
//   where it goes: only the places they may ask into (their work stations' places, or every place for stations.all), never the source.
//
// The quantity is typed in the item's base unit, as every stock figure is, and checked as a decimal string before it is sent (never a
// number). POST /transfers writes a `requested` row and moves nothing: someone at the source confirms it (the request -> confirm flow stays).
// The API's refusals (a place the person does not work at, the same place twice, an item that is not stocked) show inline in the page
// language. A success refreshes the inventory reads (the admin's Transfers list among them).

export interface TransferSubject {
  itemId: number;
  /** the item's name in the page language */
  name: string;
  /** the item's base unit code, which the quantity is in */
  unit: string;
  toLocationId: number;
  /** the name of the place that asks, in the page language */
  toName?: string;
  /** a source other than the store (the card's hint) */
  fromLocationId?: number;
  /** what the source holds, when known (the card's hint) */
  sourceAvailable?: string;
  /** what the quantity starts as (the card's shortage) */
  suggested?: string;
  /** the production request this transfer is for */
  productionRequestId?: number;
}

export interface TransferPick {
  /** the dialog's title and name ("New transfer", "Request stock") */
  title: string;
  /** the place that asks, fixed (a kitchen or bar display's own place) */
  toLocationId?: number;
  /** only these stations' own places may be chosen (the production board); one station fixes the place that asks */
  toStations?: number[];
}

type SheetProps = { onClose: () => void; onRequested?: () => void } & ({ subject: TransferSubject; pick?: undefined } | { pick: TransferPick; subject?: undefined });

/** How many items the picker lists at once; typing narrows the rest. */
const SHOWN_ITEMS = 40;

export function RequestTransferSheet(props: SheetProps) {
  return props.subject ? <FixedItemSheet subject={props.subject} onClose={props.onClose} onRequested={props.onRequested} /> : <PickItemSheet pick={props.pick} onClose={props.onClose} onRequested={props.onRequested} />;
}

/** The send of both sheets: the client UUID and Idempotency-Key kept for a retry, the quantity checked as a decimal string. */
function useTransferSend(onRequested?: () => void) {
  const keys = useIdempotencyKey();
  const client = useQueryClient();
  const clientUuid = useRef(uuid());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [invalid, setInvalid] = useState(false);
  const [sent, setSent] = useState(false);

  /** Whether `qty` is a positive decimal string; marks the field otherwise. */
  function checkQty(qty: string): boolean {
    const value = qty.trim();
    const ok = isQuantityText(value) && value.replace(/[0.]/g, "") !== "";
    setInvalid(!ok);
    return ok;
  }

  async function send(body: Omit<NewTransfer, "client_uuid">) {
    setBusy(true);
    setError(null);
    const full: NewTransfer = { client_uuid: clientUuid.current, ...body };
    try {
      await requestTransfer(full, keys.keyFor(full));
      keys.done();
      setSent(true);
      void client.invalidateQueries({ queryKey: ["inv"] });
      onRequested?.();
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(false);
    }
  }

  /** Another request from the same sheet: a new client UUID, so it is a new transfer. */
  function again() {
    clientUuid.current = uuid();
    setSent(false);
    setError(null);
    setInvalid(false);
  }

  return { busy, error, invalid, sent, checkQty, send, again, setError };
}

function FixedItemSheet({ subject, onClose, onRequested }: { subject: TransferSubject; onClose: () => void; onRequested?: () => void }) {
  const t = useTranslations("transfer");
  const errorText = useErrorText();
  const sending = useTransferSend(onRequested);
  const [qty, setQty] = useState(subject.suggested ? trimDecimal(subject.suggested) : "");
  const [note, setNote] = useState("");

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!sending.checkQty(qty)) return;
    await sending.send({
      item_id: subject.itemId,
      to_location_id: subject.toLocationId,
      ...(subject.fromLocationId !== undefined ? { from_location_id: subject.fromLocationId } : {}),
      qty: qty.trim(),
      ...(note.trim() ? { note: note.trim() } : {}),
      ...(subject.productionRequestId !== undefined ? { production_request_id: subject.productionRequestId } : {}),
    });
  }

  return (
    <Modal label={t("title", { name: subject.name })} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("title", { name: subject.name })}</h2>
      {sending.sent ? (
        <>
          <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
            {t("sent")}
          </p>
          <Button type="button" onClick={onClose}>
            {t("close")}
          </Button>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          {subject.toName ? <p className="text-base text-neutral-700">{t("to", { place: subject.toName })}</p> : null}
          {subject.sourceAvailable !== undefined ? <p className="text-base text-neutral-700">{t("source_has", { qty: trimDecimal(subject.sourceAvailable), unit: subject.unit })}</p> : null}
          <Field label={t("qty", { unit: subject.unit })} error={sending.invalid ? t("need_qty") : errorText.field(sending.error, "qty")}>
            {(fieldProps) => <TextInput {...fieldProps} inputMode="decimal" value={qty} onChange={(event) => setQty(event.target.value)} />}
          </Field>
          <Field label={t("note")} optionalLabel={t("optional")}>
            {(fieldProps) => <TextInput {...fieldProps} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          {sending.error ? <Alert>{errorText.message(sending.error)}</Alert> : null}
          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={sending.busy}>
              {sending.busy ? t("working") : t("send")}
            </Button>
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("close")}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}

/** An item that can be asked for: active and kept in stock (a made-to-order product never is). */
function stockable(item: Item): boolean {
  return item.is_active && item.deleted_at === null && item.fulfilment !== "to_order";
}

/** A place a transfer may leave from or go to: every place but the cash drawer (it holds money, not stock). */
function holdsStock(location: LocationRow): boolean {
  return location.type !== "cash_drawer";
}

/** The places this person may ask stock into: their work stations' places, or every place for stations.all (TransferAccess). */
export function requestablePlaces(me: Me | null, locations: LocationRow[]): LocationRow[] {
  if (!can(me, "transfers.request")) return [];
  const everywhere = can(me, "stations.all");
  const stations = new Set((me?.work_stations ?? []).map((station) => station.station_id));
  return locations.filter((location) => holdsStock(location) && (everywhere || (location.station_id !== null && stations.has(location.station_id))));
}

/** A station's own stock place (not its cash drawer), or null. */
export function placeOfStation(locations: LocationRow[], stationId: number): LocationRow | null {
  return locations.find((location) => location.station_id === stationId && holdsStock(location)) ?? null;
}

function nameIn(locale: Locale, row: { name_en: string; name_am: string }): string {
  return locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am;
}

function figure(value: string, unit: string): string {
  const text = groupDigits(trimDecimal(value));
  return unit ? `${text} ${unit}` : text;
}

function PickItemSheet({ pick, onClose, onRequested }: { pick: TransferPick; onClose: () => void; onRequested?: () => void }) {
  const t = useTranslations("transfer");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const sending = useTransferSend(onRequested);
  const itemsId = useId();
  const items = useItems(null);
  const locations = useLocations();
  const places = useMemo(() => (locations.data ?? []).filter(holdsStock), [locations.data]);
  const named = (row: { name_en: string; name_am: string }) => nameIn(locale, row);

  // where it goes, when the caller fixes it: the display's own place, or the one station's place the board is on
  const onlyStation = pick.toStations?.length === 1 ? pick.toStations[0] : undefined;
  const fixedToId = pick.toLocationId ?? (onlyStation !== undefined ? placeOfStation(places, onlyStation)?.id : undefined);

  // the source: the branch store unless the person chooses another
  const store = places.find((location) => location.type === "store") ?? null;
  const [chosenFrom, setChosenFrom] = useState<number | null>(null);
  const fromId = chosenFrom ?? (store && store.id !== fixedToId ? store.id : (places.find((location) => location.id !== fixedToId)?.id ?? null));
  const from = places.find((location) => location.id === fromId) ?? null;
  const stock = useStock(fromId);

  // otherwise one of the places the person may ask into (never the source)
  const fixedTo = fixedToId !== undefined ? (places.find((location) => location.id === fixedToId) ?? null) : null;
  const toStations = pick.toStations;
  const toChoices = useMemo(() => {
    const allowed = requestablePlaces(me, places);
    return toStations ? allowed.filter((location) => location.station_id !== null && toStations.includes(location.station_id)) : allowed;
  }, [me, places, toStations]);
  const [chosenTo, setChosenTo] = useState<number | null>(null);
  const toOptions = toChoices.filter((location) => location.id !== fromId);
  const toId = fixedToId ?? (chosenTo !== null && toOptions.some((location) => location.id === chosenTo) ? chosenTo : toOptions.length === 1 ? toOptions[0]!.id : null);
  const fromOptions = places.filter((location) => location.id !== fixedToId);

  // the items: what the source holds first, then the rest, each in the page language; typing narrows the list
  const [search, setSearch] = useState("");
  const [itemId, setItemId] = useState<number | null>(null);
  const available = useMemo(() => new Map((stock.data ?? []).map((line) => [line.item_id, line.available])), [stock.data]);
  const candidates = useMemo(() => {
    const held = (item: Item) => compareDecimal(available.get(item.id) ?? "0", "0") > 0;
    return (items.data ?? [])
      .filter(stockable)
      .sort((a, b) => Number(held(b)) - Number(held(a)) || nameIn(locale, a).localeCompare(nameIn(locale, b), locale));
  }, [items.data, available, locale]);
  const needle = search.trim().toLocaleLowerCase();
  const matches = needle === "" ? candidates : candidates.filter((item) => `${item.name_en} ${item.name_am} ${item.sku ?? ""}`.toLocaleLowerCase().includes(needle));
  const shown = matches.slice(0, SHOWN_ITEMS);
  const item = candidates.find((candidate) => candidate.id === itemId) ?? null;
  // the chosen item stays in the list while the person types something else
  const listed = item && !shown.some((candidate) => candidate.id === item.id) ? [item, ...shown] : shown;
  const unitOf = (row: Item) => row.base_unit_code ?? row.unit_code;
  const unit = item ? unitOf(item) : "";

  const [qty, setQty] = useState("");
  const [note, setNote] = useState("");
  const [missing, setMissing] = useState<"item" | "to" | null>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!item) return setMissing("item");
    if (toId === null) return setMissing("to");
    setMissing(null);
    if (!sending.checkQty(qty)) return;
    await sending.send({
      item_id: item.id,
      to_location_id: toId,
      ...(fromId !== null ? { from_location_id: fromId } : {}),
      qty: qty.trim(),
      ...(note.trim() ? { note: note.trim() } : {}),
    });
  }

  function another() {
    sending.again();
    setItemId(null);
    setSearch("");
    setQty("");
    setNote("");
  }

  const toPlace = toId !== null ? (places.find((location) => location.id === toId) ?? null) : null;
  const loading = items.isPending || locations.isPending;
  const loadError = items.error ?? locations.error ?? null;

  return (
    <Modal label={pick.title} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{pick.title}</h2>
      {sending.sent ? (
        <>
          <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
            {item && toPlace && from ? <span className="block font-semibold">{t("sent_what", { item: named(item), qty: figure(qty.trim(), unit), from: named(from), to: named(toPlace) })}</span> : null}
            {from ? t("sent_from", { from: named(from) }) : t("sent")}
          </p>
          <div className="flex flex-wrap gap-3">
            <Button type="button" onClick={onClose}>
              {t("close")}
            </Button>
            <Button type="button" variant="secondary" onClick={another}>
              {t("another")}
            </Button>
          </div>
        </>
      ) : loadError ? (
        <>
          <Alert>{errorText.message(loadError)}</Alert>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("close")}
          </Button>
        </>
      ) : loading ? (
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field label={t("from")}>
            {(fieldProps) => (
              <Select
                {...fieldProps}
                value={fromId ?? ""}
                onChange={(event) => {
                  setChosenFrom(Number(event.target.value));
                  sending.setError(null);
                }}
              >
                {fromOptions.map((location) => (
                  <option key={location.id} value={location.id}>
                    {named(location)}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          {fixedTo ? (
            <p className="text-base text-neutral-700">{t("to", { place: named(fixedTo) })}</p>
          ) : toChoices.length === 0 ? (
            <Alert>{t("no_destination")}</Alert>
          ) : (
            <Field label={t("to_label")} error={missing === "to" ? t("need_to") : undefined}>
              {(fieldProps) => (
                <Select
                  {...fieldProps}
                  value={toId ?? ""}
                  onChange={(event) => {
                    setChosenTo(event.target.value === "" ? null : Number(event.target.value));
                    setMissing(null);
                  }}
                >
                  <option value="">{t("choose")}</option>
                  {toOptions.map((location) => (
                    <option key={location.id} value={location.id}>
                      {named(location)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}

          <Field label={t("find_item")}>
            {(fieldProps) => <TextInput {...fieldProps} type="search" autoComplete="off" value={search} onChange={(event) => setSearch(event.target.value)} />}
          </Field>
          <fieldset className="space-y-2" aria-describedby={missing === "item" ? `${itemsId}-error` : undefined}>
            <legend className="text-sm font-semibold text-neutral-900">{t("item")}</legend>
            {listed.length === 0 ? (
              <p className="text-[15px] text-neutral-600">{t("no_match")}</p>
            ) : (
              <ul className="max-h-60 divide-y divide-neutral-100 overflow-y-auto rounded-xl border border-neutral-200">
                {listed.map((candidate) => {
                  const has = available.get(candidate.id) ?? "0";
                  const held = compareDecimal(has, "0") > 0;
                  return (
                    <li key={candidate.id}>
                      <label className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-[15px] has-[:checked]:bg-neutral-100">
                        <input
                          type="radio"
                          name={`${itemsId}-item`}
                          className="size-4 shrink-0 accent-ink-900"
                          checked={candidate.id === itemId}
                          onChange={() => {
                            setItemId(candidate.id);
                            setMissing(null);
                            sending.setError(null);
                          }}
                        />
                        <span className="min-w-0 flex-1 font-medium text-neutral-900">{named(candidate)}</span>
                        <span className={`shrink-0 tabular-nums ${held ? "text-neutral-700" : "text-neutral-600"}`}>{held ? figure(has, unitOf(candidate)) : t("none_held")}</span>
                      </label>
                    </li>
                  );
                })}
              </ul>
            )}
            {matches.length > shown.length ? <p className="text-sm text-neutral-600">{t("more_matches", { shown: shown.length, total: matches.length })}</p> : null}
            {missing === "item" ? (
              <p id={`${itemsId}-error`} className="text-sm font-medium text-red-700">
                {t("need_item")}
              </p>
            ) : null}
          </fieldset>

          {item && from ? (
            <p className="text-base text-neutral-700" data-testid="transfer-source-has">
              {compareDecimal(available.get(item.id) ?? "0", "0") > 0
                ? t("place_has", { place: named(from), qty: figure(available.get(item.id) ?? "0", unit) })
                : t("place_has_none", { place: named(from) })}
            </p>
          ) : null}

          <Field label={item ? t("qty", { unit }) : t("qty_plain")} error={sending.invalid ? t("need_qty") : errorText.field(sending.error, "qty")}>
            {(fieldProps) => <TextInput {...fieldProps} inputMode="decimal" value={qty} onChange={(event) => setQty(event.target.value)} />}
          </Field>
          <Field label={t("note")} optionalLabel={t("optional")}>
            {(fieldProps) => <TextInput {...fieldProps} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          {sending.error ? <Alert>{errorText.message(sending.error)}</Alert> : null}
          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={sending.busy || (!fixedTo && toChoices.length === 0)}>
              {sending.busy ? t("working") : t("send")}
            </Button>
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("close")}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
