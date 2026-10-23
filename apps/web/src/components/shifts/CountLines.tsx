"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import type { Count, CountLine, Locale, VarianceReason } from "@/lib/api/types";
import type { LineEdit } from "@/lib/api/counts";
import { isTouched, needsReason, prefillOf, signedQuantity, varianceOf } from "@/lib/counts";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { Badge, Button, Select, TextInput } from "../ui";

// The lines of one count (step 3.7b). While the count is a draft each line has its counted figure to type (saved when the field is left),
// an "As expected" button for the line the counter has looked at and found right, and, where the figure differs from what is expected,
// the reason and the note. Quantities are decimal strings in the item's base unit, shown as the API sent them. Once the count is
// handed in the same table reads as a record: the figures the approver sees.

export function itemName(line: CountLine, locale: Locale): string {
  return locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en;
}

export function reasonName(reason: { name_en: string; name_am: string }, locale: Locale): string {
  return locale === "am" ? reason.name_am || reason.name_en : reason.name_en;
}

function Quantity({ value, unit }: { value: string | null; unit: string }) {
  if (value === null) return <span className="text-neutral-500">—</span>;
  return (
    <span className="whitespace-nowrap">
      {trimDecimal(value)} <span className="text-sm text-neutral-600">{unit}</span>
    </span>
  );
}

function Difference({ line }: { line: CountLine }) {
  const variance = varianceOf(line);
  if (variance === null) return <span className="text-neutral-500">—</span>;
  const sign = compareDecimal(variance, "0", 4);
  const tone = sign < 0 ? "text-red-700" : sign > 0 ? "text-amber-800" : "text-neutral-700";
  return <span className={`whitespace-nowrap font-medium ${tone}`}>{signedQuantity(variance)}</span>;
}

interface RowProps {
  count: Count;
  line: CountLine;
  reasons: VarianceReason[];
  editable: boolean;
  onEdit: (lines: LineEdit[]) => void;
}

function LineRow({ count, line, reasons, editable, onEdit }: RowProps) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const name = itemName(line, locale);
  const unit = line.item.unit;
  const server = line.counted_qty === null ? "" : trimDecimal(line.counted_qty);
  const serverNote = line.note ?? "";

  const [text, setText] = useState(server);
  const [typing, setTyping] = useState(false);
  const [invalid, setInvalid] = useState(false);
  const [note, setNote] = useState(serverNote);
  const [writing, setWriting] = useState(false);
  // what the server holds replaces the field unless the counter is in the middle of typing
  useEffect(() => {
    if (!typing) setText(server);
  }, [server, typing]);
  useEffect(() => {
    if (!writing) setNote(serverNote);
  }, [serverNote, writing]);

  const touched = isTouched(line);
  const explain = needsReason(line);
  const chosen = reasons.find((reason) => reason.id === line.reason?.id) ?? null;
  const placeholder = trimDecimal(prefillOf(count, line));
  const moved = line.moved_since !== null && compareDecimal(line.moved_since, "0", 4) !== 0 ? line.moved_since : null;

  function commit() {
    setTyping(false);
    const value = text.trim();
    if (value === server) {
      setInvalid(false);
      return;
    }
    if (value === "") {
      setText(server);
      setInvalid(false);
      return;
    }
    if (!isQuantityText(value)) {
      setInvalid(true);
      return;
    }
    setInvalid(false);
    onEdit([{ item_id: line.item.id, counted_qty: value, confirm: true }]);
  }

  return (
    <tr className={touched ? "" : "bg-amber-50/40"}>
      <th scope="row" className="px-3 py-3 text-left align-top text-base font-medium text-neutral-900">
        {name}
      </th>
      <td className="px-3 py-3 align-top text-base">
        <Quantity value={line.expected_qty ?? "0"} unit={unit} />
        {moved !== null ? <span className="block text-sm text-amber-800">{t("moved_since", { qty: signedQuantity(moved), unit })}</span> : null}
      </td>
      {count.type === "opening" ? (
        <td className="px-3 py-3 align-top text-base">
          <Quantity value={line.carried_qty} unit={unit} />
        </td>
      ) : null}
      <td className="px-3 py-3 align-top text-base">
        {editable ? (
          <div className="w-36">
            <TextInput
              inputMode="decimal"
              aria-label={t("counted_for", { item: name })}
              aria-invalid={invalid || undefined}
              placeholder={placeholder}
              value={text}
              onFocus={() => setTyping(true)}
              onChange={(event) => setText(event.target.value)}
              onBlur={commit}
              onKeyDown={(event) => {
                if (event.key === "Enter") event.currentTarget.blur();
              }}
            />
            {invalid ? <p className="mt-1 text-sm font-medium text-red-700">{t("qty_invalid")}</p> : null}
          </div>
        ) : (
          <Quantity value={line.counted_qty} unit={unit} />
        )}
      </td>
      <td className="px-3 py-3 align-top text-base">
        <Difference line={line} />
      </td>
      <td className="min-w-56 px-3 py-3 align-top text-base">
        {editable ? (
          explain ? (
            <div className="space-y-2">
              <Select
                aria-label={t("reason_for", { item: name })}
                value={line.reason?.id ?? ""}
                onChange={(event) => onEdit([{ item_id: line.item.id, reason_id: event.target.value === "" ? null : Number(event.target.value) }])}
              >
                <option value="">{t("reason_choose")}</option>
                {reasons.map((reason) => (
                  <option key={reason.id} value={reason.id}>
                    {reasonName(reason, locale)}
                  </option>
                ))}
              </Select>
              {line.reason !== null ? (
                <TextInput
                  aria-label={t("note_for_line", { item: name })}
                  placeholder={chosen?.requires_note ? t("note_required") : t("note_optional")}
                  value={note}
                  maxLength={255}
                  onFocus={() => setWriting(true)}
                  onChange={(event) => setNote(event.target.value)}
                  onBlur={() => {
                    setWriting(false);
                    if (note.trim() !== serverNote.trim()) onEdit([{ item_id: line.item.id, reason_note: note.trim() === "" ? null : note.trim() }]);
                  }}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") event.currentTarget.blur();
                  }}
                />
              ) : null}
            </div>
          ) : null
        ) : (
          <>
            {line.reason ? <span className="font-medium">{reasonName(line.reason, locale)}</span> : <span className="text-neutral-500">—</span>}
            {line.note ? <span className="block text-sm text-neutral-700">{line.note}</span> : null}
            {line.decision_note ? <span className="block text-sm text-amber-800">{line.decision_note}</span> : null}
          </>
        )}
      </td>
      <td className="px-3 py-3 align-top text-base">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={touched ? "green" : "amber"}>{touched ? t("mark_counted") : t("mark_not_counted")}</Badge>
          {editable && !touched ? (
            <Button
              type="button"
              variant="secondary"
              aria-label={t("as_expected_for", { item: name })}
              onClick={() => onEdit([{ item_id: line.item.id, confirm: true }])}
            >
              {t("as_expected")}
            </Button>
          ) : null}
        </div>
      </td>
    </tr>
  );
}

export function CountLines({ count, reasons, editable, onEdit }: { count: Count; reasons: VarianceReason[]; editable: boolean; onEdit: (lines: LineEdit[]) => void }) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const lines = [...count.lines].sort((a, b) => itemName(a, locale).localeCompare(itemName(b, locale)) || a.id - b.id);
  if (lines.length === 0) return <p className="text-base text-neutral-600">{t("no_lines")}</p>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[56rem] border-collapse text-left">
        <caption className="sr-only">{t("lines_caption")}</caption>
        <thead>
          <tr className="border-b border-neutral-300 text-sm text-neutral-700">
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_item")}
            </th>
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_expected")}
            </th>
            {count.type === "opening" ? (
              <th scope="col" className="px-3 py-2 font-medium">
                {t("col_carried")}
              </th>
            ) : null}
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_counted")}
            </th>
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_difference")}
            </th>
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_reason")}
            </th>
            <th scope="col" className="px-3 py-2 font-medium">
              {t("col_mark")}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-neutral-200">
          {lines.map((line) => (
            <LineRow key={line.id} count={count} line={line} reasons={reasons} editable={editable} onEdit={onEdit} />
          ))}
        </tbody>
      </table>
    </div>
  );
}
