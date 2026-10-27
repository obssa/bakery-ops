"use client";

import { useTranslations } from "next-intl";
import { useId, useState, type FormEvent } from "react";
import { useSaveReceiptSettings } from "@/lib/api/adminPayments";
import { ApiError } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useReceiptSettings } from "@/lib/api/till";
import type { ReceiptSettings } from "@/lib/api/types";
import { NAME_MAX, receiptBody, receiptFormOf, receiptIssues, type ReceiptForm, type ReceiptIssue } from "@/lib/adminPayments";
import { ethiopianLabel } from "@/lib/dates";
import { useErrorText } from "@/lib/errors";
import { businessToday } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { Alert, Button, Field, PageHeader, Panel, TextInput, Textarea } from "../ui";
import { QueryBox } from "../inventory/shared";

// Admin > Payments > Receipts (step 4.5c): what the receipt of step 4.3e carries, the M5 columns of GET /receipt-settings (payments.take):
// the business name in both languages, the address and the footer as bilingual pairs, the phone, the TIN, whether Amharic is printed beside
// English and whether the Ethiopian date is printed too (step 4.5f: with today's date as the receipt prints it). PUT /receipt-settings
// (channels.manage, the admin) replaces the whole form; anyone else who takes payments reads it here as the till's receipt prints it.

export function ReceiptSettingsScreen() {
  const t = useTranslations("adminPayments");
  const settings = useReceiptSettings();
  return (
    <>
      <PageHeader title={t("receipts.title")} intro={t("receipts.intro")} />
      <QueryBox query={settings}>{(data) => <SettingsForm settings={data} />}</QueryBox>
    </>
  );
}

type TextField = Exclude<keyof ReceiptForm, "show_amharic" | "ethiopian_dates">;

interface FieldControlProps {
  id: string;
  "aria-describedby"?: string;
  "aria-invalid"?: boolean;
}

const FIELD_OF: Record<ReceiptIssue, keyof ReceiptForm> = {
  business_name_en: "business_name_en",
  business_name_am: "business_name_am",
  address_pair: "address_en",
  footer_pair: "footer_en",
  phone: "phone",
  tin: "tin",
  too_long: "business_name_en",
};

function SettingsForm({ settings }: { settings: ReceiptSettings }) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const editable = can(me, "channels.manage");
  const save = useSaveReceiptSettings();
  const idempotency = useIdempotencyKey();
  const [form, setForm] = useState<ReceiptForm>(() => receiptFormOf(settings));
  const [issues, setIssues] = useState<ReceiptIssue[]>([]);
  const [saved, setSaved] = useState(false);
  const dates = useDateFormat();
  const ethiopianHint = useId();
  const set = <K extends keyof ReceiptForm>(key: K, value: ReceiptForm[K]) => {
    setSaved(false);
    setForm((current) => ({ ...current, [key]: value }));
  };

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);
    const found = receiptIssues(form);
    setIssues(found);
    if (found.length > 0) return;
    const body = receiptBody(form);
    save.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (answer) => {
          idempotency.done();
          setForm(receiptFormOf(answer));
          setSaved(true);
        },
        onError: (error) => {
          if (!keepsKey(error)) idempotency.done();
        },
      },
    );
  }

  const issueFor = (field: keyof ReceiptForm) => {
    const found = issues.find((issue) => FIELD_OF[issue] === field);
    return found ? t(`receipts.issue_${found}`) : errorText.field(save.error, field);
  };
  /** The control of one text field, wired to the form (a long one is a text area). */
  function text(field: TextField, long = false) {
    const common = { disabled: !editable, maxLength: long ? 255 : field === "phone" || field === "tin" ? 32 : NAME_MAX, lang: field.endsWith("_am") ? "am" : undefined, value: form[field] };
    function render(props: FieldControlProps) {
      return long ? (
        <Textarea {...props} {...common} onChange={(event) => set(field, event.target.value)} />
      ) : (
        <TextInput {...props} {...common} onChange={(event) => set(field, event.target.value)} />
      );
    }
    return render;
  }
  const apiError = save.error instanceof ApiError ? save.error : null;

  return (
    <Panel title={t("receipts.form")} className="max-w-3xl">
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        {settings.is_default ? <p className="rounded-md bg-amber-50 px-4 py-3 text-base text-amber-900">{t("receipts.default_note")}</p> : null}
        {editable ? null : <p className="rounded-md bg-neutral-50 px-4 py-3 text-base text-neutral-700">{t("receipts.view_only")}</p>}
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t("receipts.business_name_en")} error={issueFor("business_name_en")}>
            {text("business_name_en")}
          </Field>
          <Field label={t("receipts.business_name_am")} error={issueFor("business_name_am")}>
            {text("business_name_am")}
          </Field>
          <Field label={t("receipts.address_en")} optionalLabel={t("optional")} error={issueFor("address_en")}>
            {text("address_en", true)}
          </Field>
          <Field label={t("receipts.address_am")} optionalLabel={t("optional")} error={errorText.field(save.error, "address_am")}>
            {text("address_am", true)}
          </Field>
          <Field label={t("receipts.phone")} optionalLabel={t("optional")} error={issueFor("phone")}>
            {text("phone")}
          </Field>
          <Field label={t("receipts.tin")} optionalLabel={t("optional")} error={issueFor("tin")}>
            {text("tin")}
          </Field>
          <Field label={t("receipts.footer_en")} optionalLabel={t("optional")} error={issueFor("footer_en")}>
            {text("footer_en", true)}
          </Field>
          <Field label={t("receipts.footer_am")} optionalLabel={t("optional")} error={errorText.field(save.error, "footer_am")}>
            {text("footer_am", true)}
          </Field>
        </div>
        <p className="text-sm text-neutral-600">{t("receipts.pairs_hint")}</p>
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" disabled={!editable} checked={form.show_amharic} onChange={(event) => set("show_amharic", event.target.checked)} />
          {t("receipts.show_amharic")}
        </label>
        <div>
          <label className="flex min-h-12 items-center gap-3 text-base">
            <input
              type="checkbox"
              className="size-5"
              disabled={!editable}
              checked={form.ethiopian_dates}
              aria-describedby={ethiopianHint}
              onChange={(event) => set("ethiopian_dates", event.target.checked)}
            />
            {t("receipts.ethiopian_dates")}
          </label>
          {/* the receipt's own converter (Shared\Time\EthiopianDate) and the web's (lib/ethiopianDate.ts) are twins */}
          <p id={ethiopianHint} className="pl-8 text-sm text-neutral-600">
            {t("receipts.ethiopian_today", { date: ethiopianLabel(businessToday(), dates, true) })}
          </p>
        </div>
        {save.isError && !(apiError && Object.keys(apiError.fieldErrors).length > 0) ? <Alert>{errorText.message(save.error)}</Alert> : null}
        <p role="status" aria-live="polite" className={saved ? "rounded-md bg-green-50 px-4 py-3 text-base text-green-800" : "sr-only"}>
          {saved ? t("receipts.saved") : null}
        </p>
        {editable ? (
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? t("working") : t("receipts.save")}
          </Button>
        ) : null}
      </form>
    </Panel>
  );
}
