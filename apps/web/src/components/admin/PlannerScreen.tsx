"use client";

import { useLocale, useTranslations } from "next-intl";
import { useRef, useState, type FormEvent } from "react";
import { useAssignRequest, useBakers, usePlannerRequests, useScheduleRequest } from "@/lib/api/catalog";
import { useItems } from "@/lib/api/hooks";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import { REQUEST_PRIORITIES, type Locale, type NewScheduledRequest, type ProductionRequest, type RequestPriority, type User } from "@/lib/api/types";
import { isQuantityText, compareDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { formatWhen } from "../production/RequestCard";
import { Alert, Button, Chip, Field, PageHeader, Panel, Select, Skeleton, Status, TextInput, type Tone } from "../ui";
import { ACTIONS_CELL, EmptyRow, FORM_GRID, Notice, ProductThumb, TableCard, useProductLook, type ProductLook } from "./kit";

// Admin > Planner (Phase 2, step 2.8d; docs/architecture.md section 6): the production requests still open, with their due
// times and priorities, a form that schedules one for a night's work (a product made in batches, a quantity in its base
// unit or the batch size, a due time, a priority, a note) and, for each request that is still waiting, the baker of the
// producing station to hand it to. The same calls as the production board (2.8a): POST /production/requests and
// POST /production/requests/{id}/assign. Quantities stay decimal strings.
//
// Step 6.4c (fresh-counter.md 4.4, the CatalogList board): the title row with its Amharic twin; the open requests a table card, each
// product with its 52 x 39 photo (the Admin board's top sellers) and the other language under its name, the priority and the state as
// chips, the baker picked in the row; under it the schedule form, a white card in two columns from 1024 px.

const PRIORITY_RANK: Record<RequestPriority, number> = { urgent: 0, high: 1, normal: 2 };
const PRIORITY_TONE: Record<RequestPriority, Tone> = { urgent: "red", high: "amber", normal: "neutral" };
const STATUS_TONE: Record<ProductionRequest["status"], Tone> = {
  requested: "amber",
  accepted: "blue",
  in_progress: "blue",
  pending_confirmation: "blue",
  done: "brand",
  cancelled: "neutral",
};

export function PlannerScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("planner");
  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <OpenRequests />
        <ScheduleForm />
      </div>
    </>
  );
}

function ScheduleForm() {
  const t = useTranslations("planner");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const products = useItems("product");
  const schedule = useScheduleRequest();
  const idempotency = useIdempotencyKey();
  // one client UUID for the request being written, kept for a retry and renewed after a success (rule 7)
  const clientUuid = useRef(uuid());

  const [productId, setProductId] = useState("");
  const [qty, setQty] = useState("");
  const [due, setDue] = useState("");
  const [priority, setPriority] = useState<RequestPriority>("normal");
  const [note, setNote] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  // only products the bakery makes in batches at a station can be asked for
  const makeable = (products.data ?? []).filter((product) => product.fulfilment === "to_stock" && product.production_station_id !== null && product.is_active);
  const chosen = makeable.find((product) => String(product.id) === productId);
  const nameOf = (row: { name_en: string; name_am: string }) => (locale === "am" ? row.name_am : row.name_en);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setDone(null);
    if (!chosen) return setProblem(t("check_product"));
    if (qty.trim() && (!isQuantityText(qty) || compareDecimal(qty, "0") <= 0)) return setProblem(t("check_qty"));
    const dueAt = new Date(due);
    if (!due || Number.isNaN(dueAt.getTime())) return setProblem(t("check_due"));
    setProblem(null);
    const body: NewScheduledRequest = {
      client_uuid: clientUuid.current,
      product_item_id: chosen.id,
      ...(qty.trim() ? { qty: qty.trim() } : {}),
      due_at: dueAt.toISOString(),
      priority,
      ...(note.trim() ? { note: note.trim() } : {}),
    };
    schedule.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: () => {
          idempotency.done();
          clientUuid.current = uuid();
          setDone(t("scheduled", { name: nameOf(chosen) }));
          setQty("");
          setNote("");
        },
      },
    );
  }

  return (
    <Panel title={t("schedule_title")}>
      <form onSubmit={onSubmit} className="space-y-4">
        <div className={FORM_GRID}>
        <Field label={t("field_product")} error={errorText.field(schedule.error, "product_item_id")}>
          {(props) => (
            <Select {...props} controlSize="md" required value={productId} onChange={(e) => setProductId(e.target.value)}>
              <option value="" disabled>
                {products.isPending ? tc("loading") : t("choose_product")}
              </option>
              {makeable.map((product) => (
                <option key={product.id} value={product.id}>
                  {nameOf(product)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field
          label={t("field_qty")}
          optionalLabel={t("optional")}
          hint={chosen?.default_batch_qty ? t("qty_hint", { qty: trimDecimal(chosen.default_batch_qty), unit: chosen.unit_code }) : t("qty_hint_none")}
          error={errorText.field(schedule.error, "qty")}
        >
          {(props) => <TextInput {...props} controlSize="md" className="tabular-nums" inputMode="decimal" autoComplete="off" value={qty} onChange={(e) => setQty(e.target.value)} />}
        </Field>
        <Field label={t("field_due")} error={errorText.field(schedule.error, "due_at")}>
          {(props) => <TextInput {...props} controlSize="md" type="datetime-local" required value={due} onChange={(e) => setDue(e.target.value)} />}
        </Field>
        <Field label={t("field_priority")}>
          {(props) => (
            <Select {...props} controlSize="md" value={priority} onChange={(e) => setPriority(e.target.value as RequestPriority)}>
              {REQUEST_PRIORITIES.map((p) => (
                <option key={p} value={p}>
                  {t(`priority_${p}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <div className="lg:col-span-2">
          <Field label={t("field_note")} optionalLabel={t("optional")} error={errorText.field(schedule.error, "note")}>
            {(props) => <TextInput {...props} controlSize="md" maxLength={500} autoComplete="off" value={note} onChange={(e) => setNote(e.target.value)} />}
          </Field>
        </div>
        </div>
        {problem ? <Alert>{problem}</Alert> : null}
        {schedule.isError ? <Alert>{errorText.message(schedule.error)}</Alert> : null}
        <Status>{done}</Status>
        <Button type="submit" size="md" disabled={schedule.isPending}>
          {schedule.isPending ? tc("saving") : t("schedule")}
        </Button>
      </form>
    </Panel>
  );
}

function OpenRequests() {
  const t = useTranslations("planner");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const requests = usePlannerRequests();
  const bakers = useBakers();
  const look = useProductLook();
  const canAssign = can(me, "production.plan") && can(me, "people.manage");

  // the night's order: the earliest due time first (none last), then the more urgent
  const sorted = [...(requests.data ?? [])].sort((a, b) => {
    const x = a.due_at ? Date.parse(a.due_at) : Number.POSITIVE_INFINITY;
    const y = b.due_at ? Date.parse(b.due_at) : Number.POSITIVE_INFINITY;
    return x !== y ? (x < y ? -1 : 1) : PRIORITY_RANK[a.priority] - PRIORITY_RANK[b.priority] || a.id - b.id;
  });

  if (requests.isPending) {
    return (
      <div className="space-y-3">
        <p className="text-[15px] text-neutral-600">{tc("loading")}</p>
        <div aria-hidden="true" className="space-y-3 rounded-[18px] border border-neutral-200 bg-white p-5">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-12" />
          ))}
        </div>
      </div>
    );
  }
  if (requests.isError) {
    return (
      <div className="space-y-3">
        <Alert>{errorText.message(requests.error)}</Alert>
        <Button variant="secondary" size="md" onClick={() => void requests.refetch()}>
          {tc("retry")}
        </Button>
      </div>
    );
  }
  return (
    <div className="flex min-w-0 flex-col gap-4">
      {!canAssign ? <Notice tone="neutral">{t("assign_needs_people")}</Notice> : null}
      <TableCard caption={t("open_title")} title={t("open_title")} minWidth="min-w-[760px]">
        <thead>
          <tr>
            <th scope="col">{t("col_product")}</th>
            <th scope="col" className="text-right">
              {t("col_qty")}
            </th>
            <th scope="col">{t("col_due")}</th>
            <th scope="col">{t("col_priority")}</th>
            <th scope="col">{t("col_status")}</th>
            <th scope="col">{t("col_baker")}</th>
          </tr>
        </thead>
        <tbody>
          {sorted.length === 0 ? (
            <EmptyRow span={6}>{t("none_open")}</EmptyRow>
          ) : (
            sorted.map((request) => <RequestRow key={request.id} request={request} locale={locale} look={look} people={canAssign ? (bakers.data ?? []) : null} />)
          )}
        </tbody>
      </TableCard>
    </div>
  );
}

function RequestRow({ request, locale, look, people }: { request: ProductionRequest; locale: Locale; look: ProductLook; people: User[] | null }) {
  const dates = useDateFormat();
  const t = useTranslations("planner");
  const tp = useTranslations("production");
  const errorText = useErrorText();
  const assign = useAssignRequest();
  const idempotency = useIdempotencyKey();
  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const other = locale === "am" ? request.product.name_en : request.product.name_am;
  const [userId, setUserId] = useState(request.assigned_to ? String(request.assigned_to.id) : "");

  // the people who work at the producing station
  const candidates = (people ?? []).filter((user) => user.is_active && user.stations.some((station) => station.station_id === request.station.id));
  const waiting = request.status === "requested";

  function onAssign() {
    if (!userId) return;
    const key = idempotency.keyFor({ id: request.id, userId });
    assign.mutate(
      { id: request.id, userId: Number(userId), idempotencyKey: key },
      { onSuccess: () => idempotency.done() },
    );
  }

  return (
    <tr className="align-top">
      <td className="min-w-60">
        <span className="flex items-start gap-3">
          <ProductThumb itemId={request.product.id} name={name} look={look} className="w-[52px] rounded-lg" />
          <span className="min-w-0">
            <span className="block font-semibold text-neutral-900">{name}</span>
            {other ? (
              <span lang={locale === "am" ? "en" : "am"} className="block text-[13px] text-neutral-600">
                {other}
              </span>
            ) : null}
            {request.note ? <span className="block text-[13px] italic text-neutral-600">{request.note}</span> : null}
          </span>
        </span>
      </td>
      <td className="whitespace-nowrap text-right font-semibold tabular-nums">{`${trimDecimal(request.requested_qty)} ${request.product.unit}`}</td>
      <td className="whitespace-nowrap tabular-nums text-neutral-700">{request.due_at ? formatWhen(request.due_at, dates) : "—"}</td>
      <td>
        <Chip tone={PRIORITY_TONE[request.priority]} dot>
          {t(`priority_${request.priority}`)}
        </Chip>
      </td>
      <td>
        <Chip tone={STATUS_TONE[request.status]}>{tp(`status_${request.status}` as "status_requested")}</Chip>
      </td>
      {/* the baker: picked here while the request waits (the one already named is the choice shown), named once it is under way */}
      <td className={waiting && people !== null ? ACTIONS_CELL : "min-w-44 text-neutral-700"}>
        {!(waiting && people !== null) ? (request.assigned_to?.name ?? "—") : null}
        {waiting && people !== null ? (
          <div className="flex items-center gap-2">
            <div className="w-44">
              <Select controlSize="md" aria-label={t("baker_for", { name })} value={userId} onChange={(e) => setUserId(e.target.value)}>
                <option value="" disabled>
                  {t("choose_baker")}
                </option>
                {candidates.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button type="button" variant="secondary" size="md" compact aria-label={t("assign", { name })} disabled={!userId || assign.isPending} onClick={onAssign}>
              {t("assign_short")}
            </Button>
          </div>
        ) : null}
        {assign.isError ? <p role="alert" className="mt-1 whitespace-normal text-left text-[13px] font-medium text-red-700">{errorText.message(assign.error)}</p> : null}
      </td>
    </tr>
  );
}
