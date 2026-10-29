"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { countGroup, submitCount, submitLines, type LineEdit } from "@/lib/api/counts";
import { useItems } from "@/lib/api/hooks";
import type { Count, Locale, VarianceReason } from "@/lib/api/types";
import { isQuantityText } from "@/lib/decimal";
import { stageOf, withDraftEdits } from "@/lib/counts";
import { useErrorText } from "@/lib/errors";
import { onOutboxApplied, useWaiting } from "@/lib/outbox/store";
import { Alert, Badge, Button, Field, Panel, Select, TextInput } from "../ui";
import { CashDeclaration } from "./CashDeclaration";
import { CashReviewDialog } from "./CashReviewDialog";
import { CashSummaryView } from "./CashSummaryView";
import { ChainCard } from "./ChainCard";
import { CountLines } from "./CountLines";
import { ReviewDialog } from "./ReviewDialog";
import { useKeys } from "./useKeys";

// One place's count on the count screen (step 3.7b): its lines, "Nothing changed", an item found that was not on the list, the review
// and the submit, and what has happened to the count since: waiting for a co-signature or for the approval, locked with its hash, or
// sent back (the recount shows what the approver said). A cash drawer's count has no lines: it is the till's cash declaration (step 3.7d), by denomination.

export const STAGE_TONE = {
  counting: "blue",
  waiting_cosign: "amber",
  waiting_approval: "amber",
  locked: "green",
  sent_back: "red",
} as const;

export function StageBadge({ count }: { count: Count }) {
  const t = useTranslations("counts");
  const stage = stageOf(count);
  return <Badge tone={STAGE_TONE[stage]}>{t(`stage_${stage}_badge`)}</Badge>;
}

export function CountPanel({
  shiftId,
  count,
  all,
  reasons,
  placeName,
  onChange,
}: {
  shiftId: number;
  count: Count;
  /** every revision of the shift's counts, to find the one this replaced */
  all: Count[];
  reasons: VarianceReason[];
  placeName: string;
  onChange: (count: Count) => void;
}) {
  const t = useTranslations("counts");
  const o = useTranslations("outbox");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const client = useQueryClient();
  const keys = useKeys();
  const items = useItems(null);
  // drafts saved offline wait in the outbox (step 5.2a): the count is read again once they were sent
  const group = countGroup(count.id);
  const waiting = useWaiting((item) => item.group === group);
  useEffect(
    () =>
      onOutboxApplied((item) => {
        if (item.group === group) void client.invalidateQueries({ queryKey: ["shift-counts", shiftId] });
      }),
    [group, client, shiftId],
  );

  const [saving, setSaving] = useState(0);
  const [failure, setFailure] = useState<unknown>(null);
  const [review, setReview] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitFailure, setSubmitFailure] = useState<string | null>(null);
  const [dropped, setDropped] = useState(0);
  const [addItem, setAddItem] = useState("");
  const [addQty, setAddQty] = useState("");
  const [addInvalid, setAddInvalid] = useState(false);

  const editable = count.status === "draft";
  const stage = stageOf(count);
  const previous = all.find((other) => other.superseded_by_id === count.id) ?? null;

  const addable = useMemo(() => {
    const onCount = new Set(count.lines.map((line) => line.item.id));
    return (items.data ?? [])
      .filter((item) => item.is_active && item.fulfilment !== "to_order" && !onCount.has(item.id))
      .map((item) => ({ id: item.id, name: locale === "am" ? item.name_am || item.name_en : item.name_en, unit: item.base_unit_code ?? item.unit_code }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [items.data, count.lines, locale]);

  async function write(body: { lines?: LineEdit[]; confirm_all?: boolean }): Promise<boolean> {
    const name = `lines:${count.id}:${JSON.stringify(body)}`;
    setSaving((n) => n + 1);
    setFailure(null);
    try {
      const outcome = await submitLines(count.id, body, keys.keyFor(name));
      keys.done(name);
      // queued offline (step 5.2a): the figures stay on their lines until the outbox has sent them and the count is read again
      onChange(outcome.sent ? outcome.answer : withDraftEdits(count, body.lines ?? []));
      return true;
    } catch (error) {
      setFailure(error);
      if (!(error instanceof ApiError) || error.status !== 0) keys.done(name);
      // the count may have moved under us (handed in elsewhere, or the stock changed): look again
      void client.invalidateQueries({ queryKey: ["shift-counts", shiftId] });
      return false;
    } finally {
      setSaving((n) => n - 1);
    }
  }

  async function submit() {
    const name = `submit:${count.id}`;
    setSubmitting(true);
    setSubmitFailure(null);
    try {
      const next = await submitCount(count.id, keys.keyFor(name));
      keys.done(name);
      onChange(next);
      setDropped(next.dropped_item_ids?.length ?? 0);
      setReview(false);
      void client.invalidateQueries({ queryKey: ["shift", shiftId] });
      void client.invalidateQueries({ queryKey: ["handover"] });
    } catch (error) {
      setSubmitFailure(errorText.message(error));
      if (!(error instanceof ApiError) || error.status !== 0) keys.done(name);
      void client.invalidateQueries({ queryKey: ["shift-counts", shiftId] });
    } finally {
      setSubmitting(false);
    }
  }

  async function add() {
    const qty = addQty.trim();
    if (addItem === "") return;
    if (!isQuantityText(qty)) {
      setAddInvalid(true);
      return;
    }
    setAddInvalid(false);
    if (await write({ lines: [{ item_id: Number(addItem), counted_qty: qty, confirm: true }] })) {
      setAddItem("");
      setAddQty("");
    }
  }

  const untouched = count.lines.filter((line) => !line.confirmed).length;

  return (
    <Panel title={placeName}>
      <div className="space-y-5">
        <div className="flex flex-wrap items-center gap-3">
          <Badge tone="neutral">{t(count.type === "opening" ? "type_opening" : "type_closing")}</Badge>
          <StageBadge count={count} />
          {count.seq > 1 ? <span className="text-sm text-neutral-600">{t("revision", { seq: count.seq })}</span> : null}
          {saving > 0 ? (
            <span role="status" className="text-sm text-neutral-600">
              {t("saving")}
            </span>
          ) : null}
        </div>

        {previous && previous.status === "rejected" ? (
          <div role="note" className="space-y-1 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-base text-red-900">
            <p className="font-semibold">{t("sent_back_title", { name: previous.decided_by?.name ?? "" })}</p>
            {previous.decision_note ? <p>{previous.decision_note}</p> : null}
            <p className="text-sm">{t("sent_back_hint")}</p>
          </div>
        ) : null}
        {previous && previous.status === "locked" ? (
          <p role="note" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            {t("reopened")}
          </p>
        ) : null}

        {dropped > 0 ? (
          <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            {t("dropped_notice", { count: dropped })}
          </p>
        ) : null}
        {stage === "waiting_cosign" ? <p className="rounded-md bg-amber-50 px-4 py-3 text-base text-amber-900">{t("stage_waiting_cosign")}</p> : null}
        {stage === "waiting_approval" ? <p className="rounded-md bg-amber-50 px-4 py-3 text-base text-amber-900">{t("stage_waiting_approval")}</p> : null}
        {stage === "locked" ? (
          <>
            <p className="rounded-md bg-green-50 px-4 py-3 text-base text-green-900">{t("stage_locked")}</p>
            {count.chain ? <ChainCard chain={count.chain} /> : null}
          </>
        ) : null}
        {stage === "sent_back" ? <p className="rounded-md bg-red-50 px-4 py-3 text-base text-red-900">{t("stage_sent_back")}</p> : null}
        {count.blocked_by_pending ? <Alert>{t("blocked_by_pending")}</Alert> : null}

        {count.cash ? (
          editable ? (
            <>
              <CashDeclaration count={count} cash={count.cash} reasons={reasons} onChange={onChange} onBusy={(busy) => setSaving(busy ? 1 : 0)} />
            </>
          ) : (
            <CashSummaryView count={count} cash={count.cash} />
          )
        ) : (
          <>
            {editable ? (
              <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="secondary" disabled={untouched === 0 || saving > 0} onClick={() => void write({ confirm_all: true })}>
                  {t("nothing_changed")}
                </Button>
                <p className="max-w-xl text-sm text-neutral-600">{t("nothing_changed_hint")}</p>
              </div>
            ) : null}
            <CountLines count={count} reasons={reasons} editable={editable} onEdit={(lines) => void write({ lines })} />
            {failure ? <Alert>{errorText.message(failure)}</Alert> : null}

            {editable ? (
              <section aria-label={t("add_title")} className="space-y-3 rounded-md border border-neutral-200 px-4 py-4">
                <h3 className="text-base font-semibold text-neutral-900">{t("add_title")}</h3>
                <p className="text-sm text-neutral-600">{t("add_hint")}</p>
                <div className="flex flex-wrap items-end gap-3">
                  <div className="w-72">
                    <Field label={t("add_item")}>
                      {(props) => (
                        <Select {...props} value={addItem} onChange={(event) => setAddItem(event.target.value)}>
                          <option value="">{t("add_choose")}</option>
                          {addable.map((item) => (
                            <option key={item.id} value={item.id}>
                              {item.name} ({item.unit})
                            </option>
                          ))}
                        </Select>
                      )}
                    </Field>
                  </div>
                  <div className="w-40">
                    <Field label={t("add_qty")} error={addInvalid ? t("qty_invalid") : undefined}>
                      {(props) => <TextInput {...props} inputMode="decimal" value={addQty} onChange={(event) => setAddQty(event.target.value)} />}
                    </Field>
                  </div>
                  <Button type="button" variant="secondary" disabled={addItem === "" || saving > 0} onClick={() => void add()}>
                    {t("add_button")}
                  </Button>
                </div>
              </section>
            ) : null}
          </>
        )}

        {waiting ? (
          <p role="status" className="rounded-md bg-blue-50 px-4 py-3 text-base font-medium text-blue-900">
            {o("queued")}
          </p>
        ) : null}
        {editable ? (
          <div className="flex justify-end">
            {/* handing in needs the connection and every draft on the server first */}
            <Button type="button" disabled={saving > 0 || waiting} onClick={() => setReview(true)}>
              {t("review_button")}
            </Button>
          </div>
        ) : null}
      </div>

      {review && count.cash ? (
        <CashReviewDialog count={count} cash={count.cash} reasons={reasons} busy={submitting} failure={submitFailure} onSubmit={() => void submit()} onClose={() => setReview(false)} />
      ) : null}
      {review && !count.cash ? (
        <ReviewDialog count={count} reasons={reasons} busy={submitting} failure={submitFailure} onSubmit={() => void submit()} onClose={() => setReview(false)} />
      ) : null}
    </Panel>
  );
}
