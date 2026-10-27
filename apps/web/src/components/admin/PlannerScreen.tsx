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
import { Alert, Badge, Button, Field, PageHeader, Panel, Select, Status, TextInput } from "../ui";

// Admin > Planner (Phase 2, step 2.8d; docs/architecture.md section 6): the production requests still open, with their due
// times and priorities, a form that schedules one for a night's work (a product made in batches, a quantity in its base
// unit or the batch size, a due time, a priority, a note) and, for each request that is still waiting, the baker of the
// producing station to hand it to. The same calls as the production board (2.8a): POST /production/requests and
// POST /production/requests/{id}/assign. Quantities stay decimal strings.

const PRIORITY_RANK: Record<RequestPriority, number> = { urgent: 0, high: 1, normal: 2 };

export function PlannerScreen() {
  const t = useTranslations("planner");
  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} />
      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
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
        <Field label={t("field_product")} error={errorText.field(schedule.error, "product_item_id")}>
          {(props) => (
            <Select {...props} required value={productId} onChange={(e) => setProductId(e.target.value)}>
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
          {(props) => <TextInput {...props} inputMode="decimal" autoComplete="off" value={qty} onChange={(e) => setQty(e.target.value)} />}
        </Field>
        <Field label={t("field_due")} error={errorText.field(schedule.error, "due_at")}>
          {(props) => <TextInput {...props} type="datetime-local" required value={due} onChange={(e) => setDue(e.target.value)} />}
        </Field>
        <Field label={t("field_priority")}>
          {(props) => (
            <Select {...props} value={priority} onChange={(e) => setPriority(e.target.value as RequestPriority)}>
              {REQUEST_PRIORITIES.map((p) => (
                <option key={p} value={p}>
                  {t(`priority_${p}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("field_note")} optionalLabel={t("optional")} error={errorText.field(schedule.error, "note")}>
          {(props) => <TextInput {...props} maxLength={500} autoComplete="off" value={note} onChange={(e) => setNote(e.target.value)} />}
        </Field>
        {problem ? <Alert>{problem}</Alert> : null}
        {schedule.isError ? <Alert>{errorText.message(schedule.error)}</Alert> : null}
        <Status>{done}</Status>
        <Button type="submit" disabled={schedule.isPending}>
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
  const canAssign = can(me, "production.plan") && can(me, "people.manage");

  // the night's order: the earliest due time first (none last), then the more urgent
  const sorted = [...(requests.data ?? [])].sort((a, b) => {
    const x = a.due_at ? Date.parse(a.due_at) : Number.POSITIVE_INFINITY;
    const y = b.due_at ? Date.parse(b.due_at) : Number.POSITIVE_INFINITY;
    return x !== y ? (x < y ? -1 : 1) : PRIORITY_RANK[a.priority] - PRIORITY_RANK[b.priority] || a.id - b.id;
  });

  return (
    <Panel title={t("open_title")}>
      {requests.isPending ? (
        <p className="py-6 text-base text-neutral-600">{tc("loading")}</p>
      ) : requests.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(requests.error)}</Alert>
          <Button variant="secondary" onClick={() => void requests.refetch()}>
            {tc("retry")}
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          {!canAssign ? <p className="text-base text-neutral-600">{t("assign_needs_people")}</p> : null}
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t("open_title")}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_product")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_qty")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_due")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_priority")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_status")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_baker")}</th>
                  <th scope="col" className="py-2 font-medium">{t("col_assign")}</th>
                </tr>
              </thead>
              <tbody>
                {sorted.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-6 text-neutral-600">
                      {t("none_open")}
                    </td>
                  </tr>
                ) : (
                  sorted.map((request) => <RequestRow key={request.id} request={request} locale={locale} people={canAssign ? (bakers.data ?? []) : null} />)
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Panel>
  );
}

function RequestRow({ request, locale, people }: { request: ProductionRequest; locale: Locale; people: User[] | null }) {
  const dates = useDateFormat();
  const t = useTranslations("planner");
  const tp = useTranslations("production");
  const errorText = useErrorText();
  const assign = useAssignRequest();
  const idempotency = useIdempotencyKey();
  const name = locale === "am" ? request.product.name_am : request.product.name_en;
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
    <tr className="border-b border-neutral-100 align-top last:border-0">
      <td className="py-3 pr-4">
        <span className="block font-medium text-neutral-900">{name}</span>
        {request.note ? <span className="block text-sm text-neutral-600">{request.note}</span> : null}
      </td>
      <td className="py-3 pr-4">{`${trimDecimal(request.requested_qty)} ${request.product.unit}`}</td>
      <td className="py-3 pr-4">{request.due_at ? formatWhen(request.due_at, dates) : "—"}</td>
      <td className="py-3 pr-4">
        <Badge tone={request.priority === "urgent" ? "red" : request.priority === "high" ? "amber" : "neutral"}>{t(`priority_${request.priority}`)}</Badge>
      </td>
      <td className="py-3 pr-4">{tp(`status_${request.status}` as "status_requested")}</td>
      <td className="py-3 pr-4">{request.assigned_to?.name ?? "—"}</td>
      <td className="py-3">
        {waiting && people !== null ? (
          <div className="flex flex-wrap items-center gap-2">
            <Select aria-label={t("baker_for", { name })} value={userId} onChange={(e) => setUserId(e.target.value)} className="w-48">
              <option value="" disabled>
                {t("choose_baker")}
              </option>
              {candidates.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </Select>
            <Button type="button" variant="secondary" aria-label={t("assign", { name })} disabled={!userId || assign.isPending} onClick={onAssign}>
              {t("assign_short")}
            </Button>
          </div>
        ) : null}
        {assign.isError ? <p role="alert" className="mt-1 text-sm font-medium text-red-700">{errorText.message(assign.error)}</p> : null}
      </td>
    </tr>
  );
}
