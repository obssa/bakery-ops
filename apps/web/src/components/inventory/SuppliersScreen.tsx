"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { EmptyRow, TableCard } from "@/components/admin/kit";
import { Alert, Button, Chip, Field, PageHeader, Panel, Status, TextInput } from "@/components/ui";
import { useCreateSupplier, useSuppliers, useUpdateSupplier, type Supplier } from "@/lib/api/inventory";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useErrorText } from "@/lib/errors";
import { InventoryNav, QueryBox } from "./shared";

// Admin > Inventory > Suppliers (step 2.2b): who the bakery buys from. A supplier is never deleted (purchases point at
// it); switching one off keeps its history and takes it out of the choice on a new purchase.
//
// Step 6.4c (fresh-counter.md 4.4): the title row with its Amharic twin, the section's tabs, the suppliers a table card (Active a brand
// chip, Off a neutral one) and beside it, from 1024 px, the form a white card.

export function SuppliersScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("inventory");
  const suppliers = useSuppliers();
  const [editing, setEditing] = useState<Supplier | null>(null);

  return (
    <>
      <PageHeader title={t("suppliers.title")} intro={t("suppliers.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <InventoryNav />
        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
          <QueryBox query={suppliers}>{(rows) => <SupplierTable rows={rows} onEdit={setEditing} />}</QueryBox>
          <SupplierForm key={editing?.id ?? "new"} editing={editing} onDone={() => setEditing(null)} />
        </div>
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
    <div className="flex min-w-0 flex-col gap-4">
      {update.isError ? <Alert>{errorText.message(update.error)}</Alert> : null}
      <TableCard caption={t("suppliers.list")} minWidth="min-w-[600px]">
        <thead>
          <tr>
            <th scope="col">{t("suppliers.col_name")}</th>
            <th scope="col">{t("suppliers.col_phone")}</th>
            <th scope="col">{t("suppliers.col_tin")}</th>
            <th scope="col">{t("suppliers.col_status")}</th>
            <th scope="col">
              <span className="sr-only">{t("suppliers.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <EmptyRow span={5}>{t("suppliers.none")}</EmptyRow>
          ) : (
            rows.map((supplier) => (
              <tr key={supplier.id}>
                <th scope="row" className="font-semibold text-neutral-900">
                  {supplier.name}
                  {supplier.notes ? <span className="block text-[13px] font-normal text-neutral-600">{supplier.notes}</span> : null}
                </th>
                <td className="whitespace-nowrap font-mono text-sm text-neutral-700">{supplier.phone ?? "—"}</td>
                <td className="whitespace-nowrap font-mono text-sm text-neutral-700">{supplier.tin ?? "—"}</td>
                <td>
                  {supplier.is_active ? (
                    <Chip tone="brand" dot>
                      {t("suppliers.active")}
                    </Chip>
                  ) : (
                    <Chip dot>{t("suppliers.off")}</Chip>
                  )}
                </td>
                <td className="text-right">
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="secondary" size="md" compact onClick={() => onEdit(supplier)} aria-label={t("suppliers.edit_named", { name: supplier.name })}>
                      {t("edit")}
                    </Button>
                    <Button
                      variant="secondary"
                      size="md"
                      compact
                      disabled={update.isPending}
                      onClick={() => toggle(supplier)}
                      aria-label={t(supplier.is_active ? "suppliers.switch_off_named" : "suppliers.switch_on_named", { name: supplier.name })}
                    >
                      {t(supplier.is_active ? "switch_off" : "switch_on")}
                    </Button>
                  </div>
                </td>
              </tr>
            ))
          )}
        </tbody>
      </TableCard>
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
          {(props) => <TextInput {...props} controlSize="md" value={name} onChange={(event) => setName(event.target.value)} required maxLength={160} />}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
          <Field label={t("suppliers.phone")} optionalLabel={t("optional")} error={fieldError("phone")}>
            {(props) => <TextInput {...props} controlSize="md" type="tel" value={phone} onChange={(event) => setPhone(event.target.value)} maxLength={32} />}
          </Field>
          <Field label={t("suppliers.tin")} optionalLabel={t("optional")} error={fieldError("tin")}>
            {(props) => <TextInput {...props} controlSize="md" value={tin} onChange={(event) => setTin(event.target.value)} maxLength={32} />}
          </Field>
        </div>
        <Field label={t("suppliers.notes")} optionalLabel={t("optional")} error={fieldError("notes")}>
          {(props) => <TextInput {...props} controlSize="md" value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={500} />}
        </Field>
        {mutation.isError && !Object.keys((mutation.error as { fieldErrors?: object }).fieldErrors ?? {}).length ? <Alert>{errorText.message(mutation.error)}</Alert> : null}
        <Status>{saved ? t("suppliers.saved", { name: saved }) : null}</Status>
        <div className="flex gap-2.5">
          <Button type="submit" size="md" disabled={mutation.isPending || name.trim() === ""}>
            {mutation.isPending ? tc("saving") : editing ? t("suppliers.save_changes") : t("suppliers.add")}
          </Button>
          {editing ? (
            <Button type="button" variant="secondary" size="md" onClick={onDone}>
              {t("cancel")}
            </Button>
          ) : null}
        </div>
      </form>
    </Panel>
  );
}
