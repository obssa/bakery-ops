"use client";

import { ChevronRight, Plus, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent, type RefObject } from "react";
import { EmptyRow, Fact, InnerTable, TableCard, Toolbar, ToolbarSegments } from "@/components/admin/kit";
import { ICON_STROKE } from "@/components/icons";
import { Alert, Button, Chip, Field, IconButton, Modal, PageHeader, Panel, Select, Status, TextInput, type Tone } from "@/components/ui";
import { useItems, useUnits } from "@/lib/api/hooks";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import {
  useCancelPurchase,
  useConfirmPurchase,
  useLocations,
  usePurchase,
  usePurchases,
  useReplacePurchaseLines,
  useSavePurchase,
  useSuppliers,
  type NewPurchase,
  type NewPurchaseLine,
  type Purchase,
  type PurchaseStatus,
} from "@/lib/api/inventory";
import { ApiError } from "@/lib/api/client";
import type { Item, Unit } from "@/lib/api/types";
import { addDecimal, compareDecimal, isQuantityText, multiplyDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { InventoryNav, Pager, QueryBox, stamp, useNamer } from "./shared";

// Admin > Inventory > Purchases (step 2.2b): a purchase is a draft with lines (item, quantity, unit, unit cost) until it
// is confirmed, which puts the stock in the receiving place and moves each item's moving average; a draft may be cancelled,
// a confirmed purchase is corrected with a stock adjustment, never undone. The cost columns and totals are shown only to
// those with costs.view: the API leaves them out for everyone else. A second purchase with the same supplier and invoice
// number is a warning the person can override on purpose (`allow_duplicate_invoice`).
//
// Step 6.4c (fresh-counter.md 4.4, the CatalogList board): the title row with its Amharic twin and "New purchase" as its action (as the
// catalog's "Add product"); the section's tabs; the status as the toolbar's segments; the purchases a table card (the status chips, the
// total right-aligned, the chevron "Open purchase <invoice>", the open one brand-50, the pages in its foot). The new purchase and the open
// one are white cards above the list, brought into view when they open, the cost changes after the open one.

const STATUSES: readonly PurchaseStatus[] = ["draft", "confirmed", "cancelled"];
const DUPLICATE_KEY = "purchases.invoice.duplicate";
const STATUS_TONE: Record<PurchaseStatus, Tone> = { draft: "blue", confirmed: "brand", cancelled: "neutral" };

interface LineDraft {
  uuid: string;
  itemId: string;
  qty: string;
  unitId: string;
  unitCost: string;
}

const blankLine = (): LineDraft => ({ uuid: uuid(), itemId: "", qty: "", unitId: "", unitCost: "" });

function today(): string {
  const now = new Date();
  const two = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${two(now.getMonth() + 1)}-${two(now.getDate())}`;
}

/** Units an item can be bought in: its own and the others of its family (kg and g, l and ml). */
function unitsFor(item: Item | undefined, units: Unit[]): Unit[] {
  const own = units.find((u) => u.id === item?.unit_id);
  if (!own) return units;
  const family = own.base_unit_id ?? own.id;
  return units.filter((u) => (u.base_unit_id ?? u.id) === family);
}

/** Checks the typed lines as decimal strings (never a float) and returns the API's lines, or what is wrong with which row. */
function readLines(lines: LineDraft[], t: (key: string) => string): { ok: true; lines: NewPurchaseLine[] } | { ok: false; errors: Record<number, string> } {
  const errors: Record<number, string> = {};
  const out: NewPurchaseLine[] = [];
  lines.forEach((line, index) => {
    const qtyText = line.qty.trim();
    const costText = line.unitCost.trim();
    if (!line.itemId || !line.unitId) errors[index] = t("purchases.error_item");
    else if (!isQuantityText(qtyText) || compareDecimal(qtyText, "0") <= 0) errors[index] = t("purchases.error_qty");
    else if (!isQuantityText(costText)) errors[index] = t("purchases.error_cost");
    else out.push({ client_uuid: line.uuid, item_id: Number(line.itemId), qty: qtyText, unit_id: Number(line.unitId), unit_cost: costText });
  });
  if (lines.length === 0) errors[0] = t("purchases.error_no_lines");
  return Object.keys(errors).length > 0 ? { ok: false, errors } : { ok: true, lines: out };
}

/** The line total of a typed line, or null when it cannot be worked out exactly (a cost with more than 2 decimals, a gap). */
function previewTotal(line: LineDraft): string | null {
  if (!isQuantityText(line.qty.trim()) || !isQuantityText(line.unitCost.trim())) return null;
  try {
    return multiplyDecimal(line.unitCost.trim(), line.qty.trim());
  } catch {
    return null;
  }
}

/** Brings a card into view once, when it opens (the "New purchase" button and a row's chevron sit above or beside it). */
function useShownOnOpen<T extends HTMLElement>(): RefObject<T | null> {
  const ref = useRef<T>(null);
  useEffect(() => {
    ref.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, []);
  return ref;
}

export function PurchasesScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("inventory");
  const me = useSession((s) => s.me);
  const costs = can(me, "costs.view");
  const [status, setStatus] = useState<PurchaseStatus | null>(null);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [formKey, setFormKey] = useState(0);
  const [confirmed, setConfirmed] = useState<Purchase | null>(null);
  const purchases = usePurchases(status, page);

  const label = (p: { id: number; invoice_no: string | null }) => p.invoice_no ?? `#${p.id}`;

  return (
    <>
      <PageHeader
        title={t("purchases.title")}
        intro={t("purchases.intro")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        actions={
          <Button
            size="md"
            onClick={() => {
              setCreating(true);
              setFormKey((k) => k + 1);
            }}
          >
            <Plus className="size-[18px]" strokeWidth={2.2} aria-hidden="true" />
            {t("purchases.new")}
          </Button>
        }
      />
      <div className="flex flex-col gap-6">
        <InventoryNav />
        <Toolbar>
          <ToolbarSegments
            label={t("purchases.status")}
            value={status ?? "all"}
            onChange={(next) => {
              setStatus(next === "all" ? null : next);
              setPage(1);
            }}
            segments={[{ value: "all" as const, label: t("purchases.all_statuses") }, ...STATUSES.map((value) => ({ value, label: t(`purchase_status.${value}`) }))]}
          />
        </Toolbar>

        {creating ? (
          <NewPurchasePanel
            key={formKey}
            costs={costs}
            onClose={() => setCreating(false)}
            onSaved={(purchase) => {
              setCreating(false);
              setConfirmed(null);
              setSelected(purchase.id);
            }}
          />
        ) : null}

        {selected !== null ? (
          <PurchaseDetail
            key={selected}
            id={selected}
            costs={costs}
            label={label}
            onConfirmed={setConfirmed}
            onClosed={() => {
              setSelected(null);
              setConfirmed(null);
            }}
          />
        ) : null}

        {confirmed && confirmed.id === selected ? <CostChanges purchase={confirmed} /> : null}

        <QueryBox query={purchases}>
          {(result) => (
            <TableCard
              caption={t("purchases.list")}
              minWidth="min-w-[720px]"
              footer={result.meta.last_page > 1 ? <Pager page={result.meta.current_page} lastPage={result.meta.last_page} onPage={setPage} /> : undefined}
            >
              <thead>
                <tr>
                  <th scope="col">{t("purchases.col_date")}</th>
                  <th scope="col">{t("purchases.col_supplier")}</th>
                  <th scope="col">{t("purchases.col_invoice")}</th>
                  <th scope="col">{t("purchases.col_status")}</th>
                  {costs ? (
                    <th scope="col" className="text-right">
                      {t("purchases.col_total")}
                    </th>
                  ) : null}
                  <th scope="col">
                    <span className="sr-only">{t("purchases.col_actions")}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {result.data.length === 0 ? (
                  <EmptyRow span={costs ? 6 : 5}>{t("purchases.none")}</EmptyRow>
                ) : (
                  result.data.map((purchase) => (
                    <tr key={purchase.id} className={selected === purchase.id ? "bg-brand-50 hover:bg-brand-50" : ""}>
                      <td className="whitespace-nowrap tabular-nums text-neutral-700">{purchase.purchase_date}</td>
                      <td>{purchase.supplier.name}</td>
                      <th scope="row" className="font-semibold text-neutral-900">
                        {purchase.invoice_no ?? "—"}
                      </th>
                      <td>
                        <StatusChip status={purchase.status} />
                      </td>
                      {costs ? <td className="whitespace-nowrap text-right font-semibold tabular-nums">{purchase.total ?? "—"}</td> : null}
                      <td className="text-right">
                        <IconButton
                          label={t("purchases.open_named", { label: label(purchase) })}
                          size="md"
                          onClick={() => {
                            setSelected(purchase.id);
                            setCreating(false);
                            setConfirmed(null);
                          }}
                        >
                          <ChevronRight className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                        </IconButton>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </TableCard>
          )}
        </QueryBox>
      </div>
    </>
  );
}

function StatusChip({ status }: { status: PurchaseStatus }) {
  const t = useTranslations("inventory");
  return (
    <Chip tone={STATUS_TONE[status]} dot>
      {t(`purchase_status.${status}`)}
    </Chip>
  );
}

function CostChanges({ purchase }: { purchase: Purchase }) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const items = useItems(null);
  const changes = purchase.costs ?? [];
  // the costs are rates per base unit (g for a kilogram item): say which
  const rate = (value: string, id: number) => {
    const item = items.data?.find((i) => i.id === id);
    const unit = item ? (item.base_unit_code ?? item.unit_code) : "";
    return unit ? `${value} / ${unit}` : value;
  };
  const itemOf = (id: number) => purchase.lines?.find((l) => l.item.id === id)?.item;
  if (changes.length === 0) {
    return (
      <Panel title={t("purchases.cost_changes")}>
        <p className="text-[15px] text-neutral-600">{t("purchases.no_cost_changes")}</p>
      </Panel>
    );
  }
  return (
    <TableCard caption={t("purchases.cost_changes")} title={t("purchases.cost_changes")} minWidth="min-w-[560px]">
      <thead>
        <tr>
          <th scope="col">{t("purchases.col_item")}</th>
          <th scope="col" className="text-right">
            {t("purchases.cost_before")}
          </th>
          <th scope="col" className="text-right">
            {t("purchases.cost_after")}
          </th>
        </tr>
      </thead>
      <tbody>
        {changes.map((change) => {
          const item = itemOf(change.item_id);
          return (
            <tr key={change.item_id}>
              <th scope="row" className="font-semibold text-neutral-900">
                {item ? name(item) : `#${change.item_id}`}
              </th>
              <td className="whitespace-nowrap text-right tabular-nums text-neutral-700">{change.cost_before === null ? "—" : rate(change.cost_before, change.item_id)}</td>
              <td className="whitespace-nowrap text-right font-semibold tabular-nums">{rate(change.cost_after, change.item_id)}</td>
            </tr>
          );
        })}
      </tbody>
    </TableCard>
  );
}

function PurchaseLinesEditor({
  lines,
  onChange,
  errors,
  items,
  units,
  costs,
}: {
  lines: LineDraft[];
  onChange: (lines: LineDraft[]) => void;
  errors: Record<number, string>;
  items: Item[];
  units: Unit[];
  costs: boolean;
}) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const patch = (index: number, change: Partial<LineDraft>) => onChange(lines.map((line, i) => (i === index ? { ...line, ...change } : line)));

  let total: string | null = "0.00";
  for (const line of lines) {
    const part = previewTotal(line);
    total = total !== null && part !== null ? addDecimal(total, part) : null;
  }

  return (
    <div className="space-y-3">
      <InnerTable caption={t("purchases.lines")} minWidth="min-w-[44rem]">
        <thead>
          <tr>
            <th scope="col">{t("purchases.col_item")}</th>
            <th scope="col">{t("purchases.col_qty")}</th>
            <th scope="col">{t("purchases.col_unit")}</th>
            <th scope="col">{t("purchases.col_unit_cost")}</th>
            {costs ? (
              <th scope="col" className="text-right">
                {t("purchases.col_line_total")}
              </th>
            ) : null}
            <th scope="col">
              <span className="sr-only">{t("purchases.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line, index) => {
            const n = index + 1;
            const item = items.find((i) => String(i.id) === line.itemId);
            const lineTotal = previewTotal(line);
            return (
              <tr key={line.uuid} className="align-top hover:bg-transparent">
                <td>
                  <Select
                    controlSize="md"
                    aria-label={t("purchases.item_line", { n })}
                    aria-invalid={errors[index] ? true : undefined}
                    value={line.itemId}
                    onChange={(event) => {
                      const picked = items.find((i) => String(i.id) === event.target.value);
                      patch(index, { itemId: event.target.value, unitId: picked ? String(picked.unit_id) : "" });
                    }}
                  >
                    <option value="">{t("purchases.pick_item")}</option>
                    {items.map((candidate) => (
                      <option key={candidate.id} value={candidate.id}>
                        {name(candidate)}
                      </option>
                    ))}
                  </Select>
                  {errors[index] ? <p className="mt-1 text-[13px] font-medium text-red-700">{errors[index]}</p> : null}
                </td>
                <td className="w-32">
                  <TextInput controlSize="md" className="tabular-nums" aria-label={t("purchases.qty_line", { n })} inputMode="decimal" value={line.qty} onChange={(event) => patch(index, { qty: event.target.value })} />
                </td>
                <td className="w-32">
                  <Select controlSize="md" aria-label={t("purchases.unit_line", { n })} value={line.unitId} onChange={(event) => patch(index, { unitId: event.target.value })}>
                    {line.unitId === "" ? <option value="" /> : null}
                    {unitsFor(item, units).map((unit) => (
                      <option key={unit.id} value={unit.id}>
                        {unit.code}
                      </option>
                    ))}
                  </Select>
                </td>
                <td className="w-36">
                  <TextInput controlSize="md" className="tabular-nums" aria-label={t("purchases.cost_line", { n })} inputMode="decimal" value={line.unitCost} onChange={(event) => patch(index, { unitCost: event.target.value })} />
                </td>
                {costs ? <td className="whitespace-nowrap pt-5 text-right font-semibold tabular-nums">{lineTotal ?? "—"}</td> : null}
                <td className="w-14 text-right">
                  <IconButton label={t("purchases.remove_line", { n })} size="md" disabled={lines.length === 1} onClick={() => onChange(lines.filter((_, i) => i !== index))}>
                    <X className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
                  </IconButton>
                </td>
              </tr>
            );
          })}
        </tbody>
      </InnerTable>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button variant="secondary" size="md" onClick={() => onChange([...lines, blankLine()])}>
          <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
          {t("purchases.add_line")}
        </Button>
        {costs ? <p className="text-[17px] font-semibold tabular-nums text-neutral-900">{t("purchases.total_is", { total: total ?? "—" })}</p> : null}
      </div>
    </div>
  );
}

function NewPurchasePanel({ costs, onSaved, onClose }: { costs: boolean; onSaved: (purchase: Purchase) => void; onClose: () => void }) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const errorText = useErrorText();
  const suppliers = useSuppliers();
  const locations = useLocations();
  const items = useItems(null);
  const units = useUnits();
  const save = useSavePurchase();
  const idempotency = useIdempotencyKey();
  const shown = useShownOnOpen<HTMLDivElement>();
  const [clientUuid] = useState(uuid);
  const [supplierId, setSupplierId] = useState("");
  const [invoice, setInvoice] = useState("");
  const [date, setDate] = useState(today);
  const [locationId, setLocationId] = useState("");
  const [notes, setNotes] = useState("");
  const [lines, setLines] = useState<LineDraft[]>(() => [blankLine()]);
  const [lineErrors, setLineErrors] = useState<Record<number, string>>({});
  const [allowDuplicate, setAllowDuplicate] = useState(false);

  const buyable = (items.data ?? []).filter((i) => i.is_active && (i.kind === "ingredient" || i.fulfilment === "bought_in"));
  const places = locations.data ?? [];
  const store = places.find((p) => p.type === "store");
  const duplicate = save.error instanceof ApiError && save.error.key === DUPLICATE_KEY;

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const read = readLines(lines, t);
    setLineErrors(read.ok ? {} : read.errors);
    if (!read.ok) return;
    const body: NewPurchase = {
      client_uuid: clientUuid,
      supplier_id: Number(supplierId),
      purchase_date: date,
      lines: read.lines,
      ...(invoice.trim() ? { invoice_no: invoice.trim() } : {}),
      ...(locationId || store ? { location_id: Number(locationId || store?.id) } : {}),
      ...(notes.trim() ? { notes: notes.trim() } : {}),
      ...(allowDuplicate ? { allow_duplicate_invoice: true } : {}),
    };
    save.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (purchase) => {
          idempotency.done();
          onSaved(purchase);
        },
      },
    );
  }

  const fieldError = (field: string) => errorText.field(save.error, field);

  return (
    <div ref={shown} className="scroll-mt-8">
      <Panel
        title={t("purchases.new")}
        action={
          <IconButton label={t("close")} size="md" onClick={onClose} className="-my-2 -mr-2">
            <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
          </IconButton>
        }
      >
        <form onSubmit={onSubmit} className="space-y-5" noValidate>
          <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
            <Field label={t("purchases.supplier")} error={fieldError("supplier_id")}>
              {(props) => (
                <Select {...props} controlSize="md" value={supplierId} onChange={(event) => setSupplierId(event.target.value)} required>
                  <option value="">{t("purchases.pick_supplier")}</option>
                  {(suppliers.data ?? []).filter((s) => s.is_active).map((supplier) => (
                    <option key={supplier.id} value={supplier.id}>
                      {supplier.name}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field label={t("purchases.invoice")} optionalLabel={t("optional")} error={fieldError("invoice_no")}>
              {(props) => <TextInput {...props} controlSize="md" value={invoice} onChange={(event) => setInvoice(event.target.value)} maxLength={64} />}
            </Field>
            <Field label={t("purchases.date")} error={fieldError("purchase_date")}>
              {(props) => <TextInput {...props} controlSize="md" type="date" value={date} onChange={(event) => setDate(event.target.value)} required />}
            </Field>
            <Field label={t("purchases.location")} error={fieldError("location_id")}>
              {(props) => (
                <Select {...props} controlSize="md" value={locationId || (store ? String(store.id) : "")} onChange={(event) => setLocationId(event.target.value)}>
                  {places.map((place) => (
                    <option key={place.id} value={place.id}>
                      {name(place)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
          <Field label={t("purchases.notes")} optionalLabel={t("optional")} error={fieldError("notes")}>
            {(props) => <TextInput {...props} controlSize="md" value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={500} />}
          </Field>
          <PurchaseLinesEditor lines={lines} onChange={setLines} errors={lineErrors} items={buyable} units={units.data ?? []} costs={costs} />
          {save.isError ? <Alert>{errorText.message(save.error)}</Alert> : null}
          {duplicate ? (
            <label className="flex min-h-11 items-center gap-3 text-[15px] text-neutral-900">
              <input type="checkbox" className="size-5 shrink-0 accent-brand-600" checked={allowDuplicate} onChange={(event) => setAllowDuplicate(event.target.checked)} />
              {t("purchases.allow_duplicate")}
            </label>
          ) : null}
          <div className="flex gap-2.5 border-t border-neutral-200 pt-5">
            <Button type="submit" size="md" disabled={save.isPending || supplierId === ""}>
              {save.isPending ? t("saving") : t("purchases.save_draft")}
            </Button>
            <Button type="button" variant="secondary" size="md" onClick={onClose}>
              {t("cancel")}
            </Button>
          </div>
        </form>
      </Panel>
    </div>
  );
}

function PurchaseDetail({
  id,
  costs,
  label,
  onConfirmed,
  onClosed,
}: {
  id: number;
  costs: boolean;
  label: (p: { id: number; invoice_no: string | null }) => string;
  onConfirmed: (purchase: Purchase) => void;
  onClosed: () => void;
}) {
  const t = useTranslations("inventory");
  const purchase = usePurchase(id);
  const shown = useShownOnOpen<HTMLDivElement>();
  return (
    <div ref={shown} className="scroll-mt-8">
      <QueryBox query={purchase}>
        {(p) => <PurchaseCard purchase={p} costs={costs} label={label(p)} onConfirmed={onConfirmed} onClosed={onClosed} closeText={t("close")} />}
      </QueryBox>
    </div>
  );
}

function PurchaseCard({
  purchase,
  costs,
  label,
  onConfirmed,
  onClosed,
  closeText,
}: {
  purchase: Purchase;
  costs: boolean;
  label: string;
  onConfirmed: (purchase: Purchase) => void;
  onClosed: () => void;
  closeText: string;
}) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const errorText = useErrorText();
  const items = useItems(null);
  const units = useUnits();
  const confirm = useConfirmPurchase();
  const cancel = useCancelPurchase();
  const replace = useReplacePurchaseLines();
  const confirmKey = useIdempotencyKey();
  const cancelKey = useIdempotencyKey();
  const linesKey = useIdempotencyKey();
  const [editing, setEditing] = useState<LineDraft[] | null>(null);
  const [lineErrors, setLineErrors] = useState<Record<number, string>>({});
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");
  const [note, setNote] = useState<string | null>(null);
  const draft = purchase.status === "draft";
  const lines = purchase.lines ?? [];
  const failure = confirm.error ?? cancel.error ?? replace.error;
  const buyable = (items.data ?? []).filter((i) => i.is_active && (i.kind === "ingredient" || i.fulfilment === "bought_in"));

  function startEditing() {
    setNote(null);
    setLineErrors({});
    setEditing(lines.map((l) => ({ uuid: uuid(), itemId: String(l.item.id), qty: l.qty, unitId: String(l.unit.id), unitCost: l.unit_cost ?? "" })));
  }

  function saveLines() {
    if (!editing) return;
    const read = readLines(editing, t);
    setLineErrors(read.ok ? {} : read.errors);
    if (!read.ok) return;
    const body = { lines: read.lines };
    replace.mutate(
      { id: purchase.id, body, idempotencyKey: linesKey.keyFor(body) },
      {
        onSuccess: () => {
          linesKey.done();
          setEditing(null);
          setNote(t("purchases.lines_saved"));
        },
      },
    );
  }

  function confirmPurchase() {
    setNote(null);
    confirm.mutate(
      { id: purchase.id, idempotencyKey: confirmKey.keyFor({ id: purchase.id }) },
      {
        onSuccess: (answer) => {
          confirmKey.done();
          setNote(t("purchases.confirmed_note"));
          onConfirmed(answer);
        },
      },
    );
  }

  function cancelPurchase() {
    const trimmed = reason.trim();
    cancel.mutate(
      { id: purchase.id, reason: trimmed, idempotencyKey: cancelKey.keyFor({ id: purchase.id, reason: trimmed }) },
      {
        onSuccess: () => {
          cancelKey.done();
          setCancelling(false);
          setNote(t("purchases.cancelled_note"));
        },
      },
    );
  }

  return (
    <Panel
      title={t("purchases.detail", { label })}
      action={
        <IconButton label={closeText} size="md" onClick={onClosed} className="-my-2 -mr-2">
          <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      }
    >
      <div className="space-y-5">
        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact term={t("purchases.supplier")}>{purchase.supplier.name}</Fact>
          <Fact term={t("purchases.date")}>
            <span className="tabular-nums">{purchase.purchase_date}</span>
          </Fact>
          <Fact term={t("purchases.location")}>{name(purchase.location)}</Fact>
          <Fact term={t("purchases.col_status")}>
            <StatusChip status={purchase.status} />
          </Fact>
          {costs && purchase.total !== undefined ? (
            <Fact term={t("purchases.col_total")}>
              <span className="font-semibold tabular-nums">
                {purchase.total} {purchase.currency}
              </span>
            </Fact>
          ) : null}
          {purchase.confirmed_by ? (
            <Fact term={t("purchases.confirmed_by")}>
              {purchase.confirmed_by.name} · <span className="tabular-nums">{stamp(purchase.confirmed_at)}</span>
            </Fact>
          ) : null}
          {purchase.cancelled_at ? (
            <Fact term={t("purchases.cancelled")}>
              {stamp(purchase.cancelled_at)}
              {purchase.cancel_reason ? ` · ${purchase.cancel_reason}` : ""}
            </Fact>
          ) : null}
          {purchase.notes ? <Fact term={t("purchases.notes")}>{purchase.notes}</Fact> : null}
        </dl>

        {editing ? (
          <div className="space-y-3">
            <PurchaseLinesEditor lines={editing} onChange={setEditing} errors={lineErrors} items={buyable} units={units.data ?? []} costs={costs} />
            <div className="flex gap-2.5">
              <Button size="md" disabled={replace.isPending} onClick={saveLines}>
                {replace.isPending ? t("saving") : t("purchases.save_lines")}
              </Button>
              <Button variant="secondary" size="md" onClick={() => setEditing(null)}>
                {t("cancel")}
              </Button>
            </div>
          </div>
        ) : (
          <InnerTable caption={t("purchases.lines")} minWidth="min-w-[32rem]">
            <thead>
              <tr>
                <th scope="col">{t("purchases.col_item")}</th>
                <th scope="col" className="text-right">
                  {t("purchases.col_qty")}
                </th>
                <th scope="col">{t("purchases.col_unit")}</th>
                {costs ? (
                  <th scope="col" className="text-right">
                    {t("purchases.col_unit_cost")}
                  </th>
                ) : null}
                {costs ? (
                  <th scope="col" className="text-right">
                    {t("purchases.col_line_total")}
                  </th>
                ) : null}
              </tr>
            </thead>
            <tbody>
              {lines.map((line) => (
                <tr key={line.id}>
                  <th scope="row" className="font-semibold text-neutral-900">
                    {name(line.item)}
                  </th>
                  <td className="text-right tabular-nums">{trimDecimal(line.qty)}</td>
                  <td className="text-neutral-700">{line.unit.code}</td>
                  {costs ? <td className="whitespace-nowrap text-right tabular-nums">{line.unit_cost ?? "—"}</td> : null}
                  {costs ? <td className="whitespace-nowrap text-right font-semibold tabular-nums">{line.line_total ?? "—"}</td> : null}
                </tr>
              ))}
            </tbody>
          </InnerTable>
        )}

        {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
        <Status>{note}</Status>

        {draft && !editing ? (
          <div className="flex flex-wrap gap-2.5 border-t border-neutral-200 pt-5">
            <Button size="md" disabled={confirm.isPending} onClick={confirmPurchase}>
              {confirm.isPending ? t("saving") : t("purchases.confirm")}
            </Button>
            <Button variant="secondary" size="md" onClick={startEditing}>
              {t("purchases.edit_lines")}
            </Button>
            <Button variant="danger" size="md" onClick={() => setCancelling(true)}>
              {t("purchases.cancel")}
            </Button>
          </div>
        ) : null}
      </div>

      {cancelling ? (
        <Modal label={t("purchases.cancel_title")} onClose={() => setCancelling(false)}>
          <h3 className="text-lg font-semibold text-neutral-900">{t("purchases.cancel_title")}</h3>
          <Field label={t("purchases.cancel_reason")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} controlSize="md" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} />}
          </Field>
          {cancel.isError ? <Alert>{errorText.message(cancel.error)}</Alert> : null}
          <div className="flex gap-2.5">
            <Button variant="danger" size="md" disabled={cancel.isPending} onClick={cancelPurchase}>
              {t("purchases.cancel_confirm")}
            </Button>
            <Button variant="secondary" size="md" onClick={() => setCancelling(false)}>
              {t("purchases.keep")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </Panel>
  );
}
