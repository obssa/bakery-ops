"use client";

import { useTranslations } from "next-intl";
import { useId, useState, type FormEvent, type ReactNode } from "react";
import { useSaveReceiptSettings } from "@/lib/api/adminPayments";
import { ApiError } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useReceiptSettings } from "@/lib/api/till";
import { PAPER_WIDTHS, type ReceiptSettings } from "@/lib/api/types";
import { NAME_MAX, receiptBody, receiptFormOf, receiptIssues, type ReceiptForm, type ReceiptIssue } from "@/lib/adminPayments";
import { ethiopianLabel } from "@/lib/dates";
import { useErrorText } from "@/lib/errors";
import { businessToday } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { FORM_GRID, Notice } from "../admin/kit";
import { Alert, Button, Field, PageHeader, Panel, Status, TextInput, Textarea } from "../ui";
import { QueryBox } from "../inventory/shared";
import { PaymentsNav } from "./shared";

// Admin > Payments > Receipts (step 4.5c): what the receipt of step 4.3e carries, the M5 columns of GET /receipt-settings (payments.take):
// the business name in both languages, the address and the footer as bilingual pairs, the phone, the TIN, whether Amharic is printed beside
// English and whether the Ethiopian date is printed too (step 4.5f: with today's date as the receipt prints it). PUT /receipt-settings
// (channels.manage, the admin) replaces the whole form; anyone else who takes payments reads it here as the till's receipt prints it.
// Step 5.3c adds the receipt printer's paper (80 or 58 mm, plan 10.2 D18: the print bridge draws 576 or 384 dots) and the logo switch
// (kept for when a logo can be uploaded; the printed receipt carries none in Phase 5). The go-live settings (2026-11-02) add the printing
// switch: off, the till hides Print and offers the receipt link and its QR code at once (the shop can go live without its printer).
//
// Step 6.4c (fresh-counter.md 4.4): the title row with its Amharic twin and the section's tabs; one form in two white cards from 1280 px,
// the business's words on the left (two columns of fields from 1024 px, English beside Amharic) and the printing on the right (each switch
// a checkbox with its line of help, the paper a radio group); Save and what came of it under them.

type TextField = Exclude<keyof ReceiptForm, "show_amharic" | "ethiopian_dates" | "paper_width_mm" | "print_logo" | "printing_enabled">;

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

const CHECK = "mt-0.5 size-5 shrink-0 accent-ink-900 disabled:cursor-not-allowed";

export function ReceiptSettingsScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("adminPayments");
  const settings = useReceiptSettings();
  return (
    <>
      <PageHeader title={t("receipts.title")} intro={t("receipts.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <PaymentsNav />
        <QueryBox query={settings}>{(data) => <SettingsForm settings={data} />}</QueryBox>
      </div>
    </>
  );
}

/** A switch of the receipt: a checkbox (its label names it) with its line of help under the label. */
function Toggle({ label, hint, hintId, children }: { label: string; hint?: ReactNode; hintId?: string; children: ReactNode }) {
  return (
    <div className="py-3">
      <label className="flex min-h-11 cursor-pointer items-start gap-3 text-[15px] font-semibold text-neutral-900">
        {children}
        <span className="pt-px">{label}</span>
      </label>
      {hint ? (
        <p id={hintId} className="pl-8 text-[13px] text-neutral-600">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

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
  const paperLabel = useId();
  const paperHint = useId();
  const logoHint = useId();
  const printingHint = useId();
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
    const common = { disabled: !editable, maxLength: long ? 255 : field === "phone" || field === "tin" ? 32 : NAME_MAX, lang: field.endsWith("_am") ? "am" : field.endsWith("_en") ? "en" : undefined, value: form[field] };
    function render(props: FieldControlProps) {
      return long ? (
        <Textarea {...props} {...common} onChange={(event) => set(field, event.target.value)} />
      ) : (
        <TextInput {...props} {...common} controlSize="md" onChange={(event) => set(field, event.target.value)} />
      );
    }
    return render;
  }
  const apiError = save.error instanceof ApiError ? save.error : null;

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5" noValidate>
      {settings.is_default ? <Notice tone="blue">{t("receipts.default_note")}</Notice> : null}
      {editable ? null : <Notice tone="neutral">{t("receipts.view_only")}</Notice>}
      <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <Panel title={t("receipts.text_title")}>
          <div className="space-y-4">
            <div className={FORM_GRID}>
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
            <p className="text-[13px] text-neutral-600">{t("receipts.pairs_hint")}</p>
          </div>
        </Panel>

        <Panel title={t("receipts.print_title")}>
          <div className="-mt-3 divide-y divide-neutral-200">
            <Toggle label={t("receipts.printing_enabled")} hint={t("receipts.printing_enabled_hint")} hintId={printingHint}>
              <input type="checkbox" className={CHECK} disabled={!editable} checked={form.printing_enabled} aria-describedby={printingHint} onChange={(event) => set("printing_enabled", event.target.checked)} />
            </Toggle>
            <Toggle label={t("receipts.show_amharic")}>
              <input type="checkbox" className={CHECK} disabled={!editable} checked={form.show_amharic} onChange={(event) => set("show_amharic", event.target.checked)} />
            </Toggle>
            {/* the receipt's own converter (Shared\Time\EthiopianDate) and the web's (lib/ethiopianDate.ts) are twins */}
            <Toggle label={t("receipts.ethiopian_dates")} hint={t("receipts.ethiopian_today", { date: ethiopianLabel(businessToday(), dates, true) })} hintId={ethiopianHint}>
              <input type="checkbox" className={CHECK} disabled={!editable} checked={form.ethiopian_dates} aria-describedby={ethiopianHint} onChange={(event) => set("ethiopian_dates", event.target.checked)} />
            </Toggle>
            <div role="radiogroup" aria-labelledby={paperLabel} aria-describedby={paperHint} className="py-3">
              <p id={paperLabel} className="pb-1 text-[15px] font-semibold text-neutral-900">
                {t("receipts.paper_width")}
              </p>
              {PAPER_WIDTHS.map((width) => (
                <label key={width} className="flex min-h-11 cursor-pointer items-center gap-3 text-[15px] text-neutral-900">
                  <input type="radio" name="paper_width_mm" className="size-5 shrink-0 accent-ink-900" disabled={!editable} checked={form.paper_width_mm === width} onChange={() => set("paper_width_mm", width)} />
                  {t(`receipts.paper_${width}`)}
                </label>
              ))}
              <p id={paperHint} className="text-[13px] text-neutral-600">
                {t("receipts.paper_hint")}
              </p>
            </div>
            <Toggle label={t("receipts.print_logo")} hint={t("receipts.print_logo_hint")} hintId={logoHint}>
              <input type="checkbox" className={CHECK} disabled={!editable} checked={form.print_logo} aria-describedby={logoHint} onChange={(event) => set("print_logo", event.target.checked)} />
            </Toggle>
          </div>
        </Panel>
      </div>
      {save.isError && !(apiError && Object.keys(apiError.fieldErrors).length > 0) ? <Alert>{errorText.message(save.error)}</Alert> : null}
      <Status>{saved ? t("receipts.saved") : null}</Status>
      {editable ? (
        <div>
          <Button type="submit" size="md" disabled={save.isPending}>
            {save.isPending ? t("working") : t("receipts.save")}
          </Button>
        </div>
      ) : null}
    </form>
  );
}
