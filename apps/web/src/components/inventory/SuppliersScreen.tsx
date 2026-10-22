"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Alert, Badge, Button, Field, PageHeader, Panel, Status, TextInput } from "@/components/ui";
import { useCreateSupplier, useSuppliers, useUpdateSupplier, type Supplier } from "@/lib/api/inventory";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useErrorText } from "@/lib/errors";
import { QueryBox, TD, TH } from "./shared";

// Admin > Inventory > Suppliers (step 2.2b): who the bakery buys from. A supplier is never deleted (purchases point at
// it); switching one off keeps its history and takes it out of the choice on a new purchase.

export function SuppliersScreen() {
  const t = useTranslations("inventory");
  const suppliers = useSuppliers();
  const [editing, setEditing] = useState<Supplier | null>(null);

  return (
    <>
      <PageHeader title={t("suppliers.title")} intro={t("suppliers.intro")} />
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
        <Panel title={t("suppliers.list")}>
          <QueryBox query={suppliers}>{(rows) => <SupplierTable rows={rows} onEdit={setEditing} />}</QueryBox>
        </Panel>
        <SupplierForm key={editing?.id ?? "new"} editing={editing} onDone={() => setEditing(null)} />
      </div>
    </>
  );
}

function SupplierTable({ rows, onEdit }: { rows: Supplier[]; onEdit: (supplier: Supplier) => void }) {
  const t = useTranslations("inventory");
  const errorText = useErrorText();
  const update = useUpdateSupplier();
  const idempotency = useIdempotencyKey();

  function toggle(supplier: Supplier) {
    const body = { is_active: !supplier.is_active };
    update.mutate({ id: supplier.id, body, idempotencyKey: idempotency.keyFor({ id: supplier.id, ...body }) }, { onSuccess: () => idempotency.done() });
  }

  return (
    <div className="space-y-3">
      {update.isError ? <Alert>{errorText.message(update.error)}</Alert> : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("suppliers.list")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("suppliers.col_name")}</th>
              <th scope="col" className={TH}>{t("suppliers.col_phone")}</th>
              <th scope="col" className={TH}>{t("suppliers.col_tin")}</th>
              <th scope="col" className={TH}>{t("suppliers.col_status")}</th>
              <th scope="col" className="py-2 font-medium">{t("suppliers.col_actions")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-6 text-neutral-600">{t("suppliers.none")}</td>
              </tr>
            ) : (
              rows.map((supplier) => (
                <tr key={supplier.id} className="border-b border-neutral-100 last:border-0">
                  <th scope="row" className={`${TD} font-medium text-neutral-900`}>
                    {supplier.name}
                    {supplier.notes ? <span className="block text-sm font-normal text-neutral-600">{supplier.notes}</span> : null}
                  </th>
                  <td className={`${TD} font-mono text-sm`}>{supplier.phone ?? "—"}</td>
                  <td className={`${TD} font-mono text-sm`}>{supplier.tin ?? "—"}</td>
                  <td className={TD}>{supplier.is_active ? <Badge tone="green">{t("suppliers.active")}</Badge> : <Badge>{t("suppliers.off")}</Badge>}</td>
                  <td className="py-3">
                    <div className="flex flex-wrap gap-2">
                      <Button variant="secondary" onClick={() => onEdit(supplier)} aria-label={t("suppliers.edit_named", { name: supplier.name })}>
                        {t("edit")}
                      </Button>
                      <Button variant="secondary" disabled={update.isPending} onClick={() => toggle(supplier)} aria-label={t(supplier.is_active ? "suppliers.switch_off_named" : "suppliers.switch_on_named", { name: supplier.name })}>
                        {t(supplier.is_active ? "switch_off" : "switch_on")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function SupplierForm({ editing, onDone }: { editing: Supplier | null; onDone: () => void }) {
  const t = useTranslations("inventory");
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const create = useCreateSupplier();
  const update = useUpdateSupplier();
  const idempotency = useIdempotencyKey();
  const [name, setName] = useState(editing?.name ?? "");
  const [phone, setPhone] = useState(editing?.phone ?? "");
  const [tin, setTin] = useState(editing?.tin ?? "");
  const [notes, setNotes] = useState(editing?.notes ?? "");
  const [saved, setSaved] = useState<string | null>(null);
  const mutation = editing ? update : create;

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    const body = { name: name.trim(), phone: phone.trim(), tin: tin.trim(), notes: notes.trim() };
    const done = () => {
      idempotency.done();
      setSaved(body.name);
      if (editing) onDone();
      else {
        setName("");
        setPhone("");
        setTin("");
        setNotes("");
      }
    };
    if (editing) update.mutate({ id: editing.id, body, idempotencyKey: idempotency.keyFor({ id: editing.id, ...body }) }, { onSuccess: done });
    else create.mutate({ body, idempotencyKey: idempotency.keyFor(body) }, { onSuccess: done });
  }

  const fieldError = (field: string) => errorText.field(mutation.error, field);

  return (
    <Panel title={editing ? t("suppliers.edit") : t("suppliers.new")}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <Field label={t("suppliers.name")} error={fieldError("name")}>
          {(props) => <TextInput {...props} value={name} onChange={(event) => setName(event.target.value)} required maxLength={160} />}
        </Field>
        <Field label={t("suppliers.phone")} optionalLabel={t("optional")} error={fieldError("phone")}>
          {(props) => <TextInput {...props} type="tel" value={phone} onChange={(event) => setPhone(event.target.value)} maxLength={32} />}
        </Field>
        <Field label={t("suppliers.tin")} optionalLabel={t("optional")} error={fieldError("tin")}>
          {(props) => <TextInput {...props} value={tin} onChange={(event) => setTin(event.target.value)} maxLength={32} />}
        </Field>
        <Field label={t("suppliers.notes")} optionalLabel={t("optional")} error={fieldError("notes")}>
          {(props) => <TextInput {...props} value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={500} />}
        </Field>
        {mutation.isError && !Object.keys((mutation.error as { fieldErrors?: object }).fieldErrors ?? {}).length ? <Alert>{errorText.message(mutation.error)}</Alert> : null}
        <Status>{saved ? t("suppliers.saved", { name: saved }) : null}</Status>
        <div className="flex gap-3">
          <Button type="submit" disabled={mutation.isPending || name.trim() === ""}>
            {mutation.isPending ? tc("saving") : editing ? t("suppliers.save_changes") : t("suppliers.add")}
          </Button>
          {editing ? (
            <Button type="button" variant="secondary" onClick={onDone}>
              {t("cancel")}
            </Button>
          ) : null}
        </div>
      </form>
    </Panel>
  );
}
