"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Alert, Badge, Button, Field, Modal, PageHeader, Panel, Select, Status, TextInput } from "@/components/ui";
import { useItems } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import {
  useCreateReorderRule,
  useDeleteReorderRule,
  useLocations,
  useReorderList,
  useReorderRules,
  useUpdateReorderRule,
  type ReorderRule,
} from "@/lib/api/inventory";
import { ROLES } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { QueryBox, qty, TD, TH, useNamer } from "./shared";

// Admin > Inventory > Reorder (step 2.6a and 2.6b): the rules that say how little of an item is too little (for one place,
// or for the whole branch) and how much to order then, and the reorder list they produce: what is at or under its minimum,
// with the days of cover the last 7 days of use leave and the quantity to order. Rules are changed by those who may manage
// inventory (admin); everyone who sees stock sees the list.

export function ReorderScreen() {
  const t = useTranslations("inventory");
  const me = useSession((s) => s.me);
  const manage = can(me, "inventory.manage");
  const rules = useReorderRules();
  const [editing, setEditing] = useState<ReorderRule | null>(null);

  return (
    <>
      <PageHeader title={t("reorder.title")} intro={t("reorder.intro")} />
      <div className="space-y-6">
        <ReorderListPanel />
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
          <Panel title={t("reorder.rules")}>
            <QueryBox query={rules}>{(rows) => <RulesTable rows={rows} manage={manage} onEdit={setEditing} />}</QueryBox>
          </Panel>
          {manage ? <RuleForm key={editing?.id ?? "new"} editing={editing} onDone={() => setEditing(null)} /> : null}
        </div>
      </div>
    </>
  );
}

function ReorderListPanel() {
  const t = useTranslations("inventory");
  const name = useNamer();
  const list = useReorderList();
  return (
    <Panel title={t("reorder.list")}>
      <QueryBox query={list}>
        {(rows) => (
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t("reorder.list")}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className={TH}>{t("reorder.col_item")}</th>
                  <th scope="col" className={TH}>{t("reorder.col_place")}</th>
                  <th scope="col" className={TH}>{t("reorder.col_available")}</th>
                  <th scope="col" className={TH}>{t("reorder.col_min")}</th>
                  <th scope="col" className={TH}>{t("reorder.col_cover")}</th>
                  <th scope="col" className={TH}>{t("reorder.col_suggested")}</th>
                  <th scope="col" className="py-2 font-medium">{t("reorder.col_state")}</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-6 text-neutral-600">{t("reorder.list_empty")}</td>
                  </tr>
                ) : (
                  rows.map((row) => (
                    <tr key={`${row.item.id}-${row.location?.id ?? 0}`} className="border-b border-neutral-100 last:border-0">
                      <th scope="row" className={`${TD} font-medium text-neutral-900`}>{name(row.item)}</th>
                      <td className={TD}>{row.location ? name(row.location) : t("whole_branch")}</td>
                      <td className={TD}>{qty(row.available, row.item.unit)}</td>
                      <td className={TD}>{qty(row.min_qty, row.item.unit)}</td>
                      <td className={TD}>{row.days_of_cover === null ? t("reorder.no_use") : t("reorder.days", { days: trimDecimal(row.days_of_cover) })}</td>
                      <td className={`${TD} font-medium`}>{qty(row.suggested_qty, row.item.unit)}</td>
                      <td className="py-3">{row.state === "out" ? <Badge tone="red">{t("state.out")}</Badge> : <Badge tone="amber">{t("state.low")}</Badge>}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </QueryBox>
    </Panel>
  );
}

function RulesTable({ rows, manage, onEdit }: { rows: ReorderRule[]; manage: boolean; onEdit: (rule: ReorderRule) => void }) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const errorText = useErrorText();
  const update = useUpdateReorderRule();
  const remove = useDeleteReorderRule();
  const updateKey = useIdempotencyKey();
  const removeKey = useIdempotencyKey();
  const [deleting, setDeleting] = useState<ReorderRule | null>(null);

  function toggle(rule: ReorderRule) {
    const body = { is_active: !rule.is_active };
    update.mutate({ id: rule.id, body, idempotencyKey: updateKey.keyFor({ id: rule.id, ...body }) }, { onSuccess: () => updateKey.done() });
  }

  function deleteRule() {
    if (!deleting) return;
    remove.mutate(
      { id: deleting.id, idempotencyKey: removeKey.keyFor({ id: deleting.id }) },
      {
        onSuccess: () => {
          removeKey.done();
          setDeleting(null);
        },
      },
    );
  }

  return (
    <div className="space-y-3">
      {update.isError ? <Alert>{errorText.message(update.error)}</Alert> : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("reorder.rules")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("reorder.col_item")}</th>
              <th scope="col" className={TH}>{t("reorder.col_place")}</th>
              <th scope="col" className={TH}>{t("reorder.col_min")}</th>
              <th scope="col" className={TH}>{t("reorder.col_order_qty")}</th>
              <th scope="col" className={TH}>{t("reorder.col_status")}</th>
              {manage ? <th scope="col" className="py-2 font-medium">{t("reorder.col_actions")}</th> : null}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={manage ? 6 : 5} className="py-6 text-neutral-600">{t("reorder.none")}</td>
              </tr>
            ) : (
              rows.map((rule) => {
                const itemName = name(rule.item);
                return (
                  <tr key={rule.id} className="border-b border-neutral-100 last:border-0">
                    <th scope="row" className={`${TD} font-medium text-neutral-900`}>{itemName}</th>
                    <td className={TD}>{rule.location ? name(rule.location) : t("whole_branch")}</td>
                    <td className={TD}>{qty(rule.min_qty, rule.item.unit)}</td>
                    <td className={TD}>{qty(rule.reorder_qty, rule.item.unit)}</td>
                    <td className={TD}>{rule.is_active ? <Badge tone="green">{t("reorder.active")}</Badge> : <Badge>{t("reorder.off")}</Badge>}</td>
                    {manage ? (
                      <td className="py-3">
                        <div className="flex flex-wrap gap-2">
                          <Button variant="secondary" aria-label={t("reorder.edit_named", { item: itemName })} onClick={() => onEdit(rule)}>
                            {t("edit")}
                          </Button>
                          <Button variant="secondary" disabled={update.isPending} aria-label={t(rule.is_active ? "reorder.switch_off_named" : "reorder.switch_on_named", { item: itemName })} onClick={() => toggle(rule)}>
                            {t(rule.is_active ? "switch_off" : "switch_on")}
                          </Button>
                          <Button variant="secondary" aria-label={t("reorder.delete_named", { item: itemName })} onClick={() => setDeleting(rule)}>
                            {t("delete")}
                          </Button>
                        </div>
                      </td>
                    ) : null}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
      {deleting ? (
        <Modal label={t("reorder.delete_title")} onClose={() => setDeleting(null)}>
          <h3 className="text-lg font-semibold text-neutral-900">{t("reorder.delete_title")}</h3>
          <p className="text-base text-neutral-700">{t("reorder.delete_about", { item: name(deleting.item) })}</p>
          {remove.isError ? <Alert>{errorText.message(remove.error)}</Alert> : null}
          <div className="flex gap-3">
            <Button disabled={remove.isPending} onClick={deleteRule}>
              {t("reorder.delete_confirm")}
            </Button>
            <Button variant="secondary" onClick={() => setDeleting(null)}>
              {t("cancel")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function RuleForm({ editing, onDone }: { editing: ReorderRule | null; onDone: () => void }) {
  const t = useTranslations();
  const ti = useTranslations("inventory");
  const name = useNamer();
  const errorText = useErrorText();
  const items = useItems(null);
  const locations = useLocations();
  const create = useCreateReorderRule();
  const update = useUpdateReorderRule();
  const idempotency = useIdempotencyKey();
  const [itemId, setItemId] = useState(editing ? String(editing.item.id) : "");
  const [placeId, setPlaceId] = useState(editing?.location ? String(editing.location.id) : "");
  const [min, setMin] = useState(editing ? trimDecimal(editing.min_qty) : "");
  const [order, setOrder] = useState(editing ? trimDecimal(editing.reorder_qty) : "");
  const [roles, setRoles] = useState<string[]>(editing?.notify_roles ?? []);
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const mutation = editing ? update : create;
  const unit = editing?.item.unit ?? items.data?.find((i) => String(i.id) === itemId)?.base_unit_code ?? items.data?.find((i) => String(i.id) === itemId)?.unit_code;

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    if (!editing && itemId === "") return setProblem(ti("reorder.error_item"));
    if (!isQuantityText(min)) return setProblem(ti("reorder.error_min"));
    if (!isQuantityText(order) || compareDecimal(order, "0") <= 0) return setProblem(ti("reorder.error_order"));
    setProblem(null);
    const done = (label: string) => () => {
      idempotency.done();
      setSaved(label);
      if (editing) onDone();
      else {
        setItemId("");
        setPlaceId("");
        setMin("");
        setOrder("");
        setRoles([]);
      }
    };
    const picked = items.data?.find((i) => String(i.id) === itemId);
    if (editing) {
      const body = { min_qty: min.trim(), reorder_qty: order.trim(), ...(roles.length > 0 ? { notify_roles: roles } : {}) };
      update.mutate({ id: editing.id, body, idempotencyKey: idempotency.keyFor({ id: editing.id, ...body }) }, { onSuccess: done(name(editing.item)) });
    } else {
      const body = { item_id: Number(itemId), min_qty: min.trim(), reorder_qty: order.trim(), ...(placeId ? { location_id: Number(placeId) } : {}), ...(roles.length > 0 ? { notify_roles: roles } : {}) };
      create.mutate({ body, idempotencyKey: idempotency.keyFor(body) }, { onSuccess: done(picked ? name(picked) : "") });
    }
  }

  const fieldError = (field: string) => errorText.field(mutation.error, field);

  return (
    <Panel title={editing ? ti("reorder.edit") : ti("reorder.new")}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <Field label={ti("reorder.item")} error={fieldError("item_id")}>
          {(props) => (
            <Select {...props} value={itemId} disabled={!!editing} onChange={(event) => setItemId(event.target.value)}>
              {editing ? <option value={itemId}>{name(editing.item)}</option> : <option value="">{ti("reorder.pick_item")}</option>}
              {editing ? null : (items.data ?? []).filter((i) => i.is_active && i.fulfilment !== "to_order").map((item) => (
                <option key={item.id} value={item.id}>
                  {name(item)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={ti("reorder.place")} hint={ti("reorder.place_hint")} error={fieldError("location_id")}>
          {(props) => (
            <Select {...props} value={placeId} disabled={!!editing} onChange={(event) => setPlaceId(event.target.value)}>
              <option value="">{ti("whole_branch")}</option>
              {(locations.data ?? []).filter((place) => place.type !== "cash_drawer").map((place) => (
                <option key={place.id} value={place.id}>
                  {name(place)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={ti("reorder.min")} hint={unit ? ti("reorder.in_unit", { unit }) : undefined} error={fieldError("min_qty")}>
          {(props) => <TextInput {...props} inputMode="decimal" value={min} onChange={(event) => setMin(event.target.value)} />}
        </Field>
        <Field label={ti("reorder.order_qty")} hint={unit ? ti("reorder.in_unit", { unit }) : undefined} error={fieldError("reorder_qty")}>
          {(props) => <TextInput {...props} inputMode="decimal" value={order} onChange={(event) => setOrder(event.target.value)} />}
        </Field>
        <fieldset className="space-y-1">
          <legend className="text-sm font-medium text-neutral-800">{ti("reorder.notify")}</legend>
          <p className="text-sm text-neutral-600">{ti("reorder.notify_hint")}</p>
          <div className="grid grid-cols-2 gap-x-3">
            {[...ROLES, "station"].map((role) => (
              <label key={role} className="flex min-h-12 items-center gap-3 text-base text-neutral-900">
                <input
                  type="checkbox"
                  className="size-6"
                  checked={roles.includes(role)}
                  onChange={(event) => setRoles(event.target.checked ? [...roles, role] : roles.filter((r) => r !== role))}
                />
                {role === "station" ? ti("reorder.role_station") : t(`people.roles.${role}`)}
              </label>
            ))}
          </div>
        </fieldset>
        {problem ? <Alert>{problem}</Alert> : null}
        {mutation.isError ? <Alert>{errorText.message(mutation.error)}</Alert> : null}
        <Status>{saved ? ti("reorder.saved", { item: saved }) : null}</Status>
        <div className="flex gap-3">
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? ti("saving") : ti("reorder.save")}
          </Button>
          {editing ? (
            <Button type="button" variant="secondary" onClick={onDone}>
              {ti("cancel")}
            </Button>
          ) : null}
        </div>
      </form>
    </Panel>
  );
}
