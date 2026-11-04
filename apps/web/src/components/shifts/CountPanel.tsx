"use client";

import { useQueryClient } from "@tanstack/react-query";
import { CircleCheck, CircleDot, Lock, Plus, Undo2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { ApiError } from "@/lib/api/client";
import { countGroup, submitCount, submitLines, type LineEdit } from "@/lib/api/counts";
import { useItems } from "@/lib/api/hooks";
import type { Count, Locale, VarianceReason } from "@/lib/api/types";
import { isQuantityText } from "@/lib/decimal";
import { stageOf, withDraftEdits } from "@/lib/counts";
import { useErrorText } from "@/lib/errors";
import { onOutboxApplied, useWaiting } from "@/lib/outbox/store";
import { ICON_STROKE } from "../icons";
import { Alert, Badge, Button, Field, Panel, Select, TextInput } from "../ui";
import { CashDeclaration } from "./CashDeclaration";
import { CashReviewDialog } from "./CashReviewDialog";
import { CashSummaryView } from "./CashSummaryView";
import { ChainCard } from "./ChainCard";
import { CountLines } from "./CountLines";
import { ReviewDialog } from "./ReviewDialog";
import { useKeys } from "./useKeys";

// One place's count on the count screen (step 3.7b; on the Fresh Counter primitives since step 6.3c): a panel named by the place, with
// the kind of count, its stage and its attempt beside the name; what has happened to the count (waiting for a co-signature or for the
// approval, locked with its hash, sent back with what the approver said); "Nothing changed" over the lines; an item found that was not
// on the list; and the one main action, "Review and submit", at the foot. A cash drawer's count has no lines: it is the till's cash
// declaration (step 3.7d), by denomination.

// locked is brand teal (the count is done and right); green means money confirmed and nothing else (fresh-counter.md 1.3)
export const STAGE_TONE = {
  counting: "blue",
  waiting_cosign: "amber",
  waiting_approval: "amber",
  locked: "brand",
  sent_back: "red",
} as const;

export function StageBadge({ count }: { count: Count }) {
  const t = useTranslations("counts");
  const stage = stageOf(count);
  return (
    <Badge tone={STAGE_TONE[stage]} dot>
      {t(`stage_${stage}_badge`)}
    </Badge>
  );
}

/** A notice in the panel: a tinted box with a line icon, in the tone of what it says. */
function Notice({ tone, icon, children, role }: { tone: "amber" | "brand" | "red" | "blue"; icon: ReactNode; children: ReactNode; role?: "note" | "status" }) {
  const look = {
    amber: "border-amber-200 bg-amber-50 text-amber-900",
    brand: "border-brand-100 bg-brand-50 text-brand-700",
    red: "border-red-200 bg-red-50 text-red-800",
    blue: "border-blue-200 bg-blue-50 text-blue-800",
  }[tone];
  return (
    <div role={role} className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-base ${look}`}>
      <span aria-hidden="true" className="mt-0.5 shrink-0">
        {icon}
      </span>
      <div className="min-w-0 flex-1 space-y-1">{children}</div>
    </div>
  );
}

const ICON = "size-5";

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
    <Panel
      title={placeName}
      action={
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone="neutral">{t(count.type === "opening" ? "type_opening" : "type_closing")}</Badge>
          <StageBadge count={count} />
          {count.seq > 1 ? <span className="text-[13px] text-neutral-600">{t("revision", { seq: count.seq })}</span> : null}
          {saving > 0 ? (
            <span role="status" className="text-[13px] text-neutral-600">
              {t("saving")}
            </span>
          ) : null}
        </div>
      }
    >
      <div className="space-y-5">
        {previous && previous.status === "rejected" ? (
          <Notice role="note" tone="red" icon={<Undo2 className={ICON} strokeWidth={ICON_STROKE} />}>
            <p className="font-semibold">{t("sent_back_title", { name: previous.decided_by?.name ?? "" })}</p>
            {previous.decision_note ? <p>{previous.decision_note}</p> : null}
            <p className="text-sm">{t("sent_back_hint")}</p>
          </Notice>
        ) : null}
        {previous && previous.status === "locked" ? (
          <Notice role="note" tone="amber" icon={<Undo2 className={ICON} strokeWidth={ICON_STROKE} />}>
            <p>{t("reopened")}</p>
          </Notice>
        ) : null}

        {dropped > 0 ? (
          <Notice role="status" tone="amber" icon={<CircleDot className={ICON} strokeWidth={ICON_STROKE} />}>
            <p>{t("dropped_notice", { count: dropped })}</p>
          </Notice>
        ) : null}
        {stage === "waiting_cosign" ? (
          <Notice tone="amber" icon={<CircleDot className={ICON} strokeWidth={ICON_STROKE} />}>
            <p>{t("stage_waiting_cosign")}</p>
          </Notice>
        ) : null}
        {stage === "waiting_approval" ? (
          <Notice tone="amber" icon={<CircleDot className={ICON} strokeWidth={ICON_STROKE} />}>
            <p>{t("stage_waiting_approval")}</p>
          </Notice>
        ) : null}
        {stage === "locked" ? (
          <>
            <Notice tone="brand" icon={<Lock className={ICON} strokeWidth={ICON_STROKE} />}>
              <p className="font-medium">{t("stage_locked")}</p>
            </Notice>
            {count.chain ? <ChainCard chain={count.chain} /> : null}
          </>
        ) : null}
        {stage === "sent_back" ? (
          <Notice tone="red" icon={<Undo2 className={ICON} strokeWidth={ICON_STROKE} />}>
            <p>{t("stage_sent_back")}</p>
          </Notice>
        ) : null}
        {count.blocked_by_pending ? <Alert>{t("blocked_by_pending")}</Alert> : null}

        {count.cash ? (
          editable ? (
            <CashDeclaration count={count} cash={count.cash} reasons={reasons} onChange={onChange} onBusy={(busy) => setSaving(busy ? 1 : 0)} />
          ) : (
            <CashSummaryView count={count} cash={count.cash} />
          )
        ) : (
          <>
            {editable ? (
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-neutral-50 px-4 py-3">
                <Button type="button" variant="secondary" size="lg" disabled={untouched === 0 || saving > 0} onClick={() => void write({ confirm_all: true })}>
                  <CircleCheck className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
                  {t("nothing_changed")}
                </Button>
                <p className="min-w-0 flex-1 basis-72 text-[13px] text-neutral-600">{t("nothing_changed_hint")}</p>
              </div>
            ) : null}
            <CountLines count={count} reasons={reasons} editable={editable} onEdit={(lines) => void write({ lines })} />
            {failure ? <Alert>{errorText.message(failure)}</Alert> : null}

            {editable ? (
              <section aria-label={t("add_title")} className="space-y-3 rounded-2xl border border-dashed border-neutral-300 px-4 py-4">
                <div className="space-y-0.5">
                  <h3 className="text-base font-semibold text-neutral-900">{t("add_title")}</h3>
                  <p className="text-[13px] text-neutral-600">{t("add_hint")}</p>
                </div>
                <div className="flex flex-wrap items-end gap-3">
                  <div className="w-72 max-w-full">
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
                      {(props) => <TextInput {...props} inputMode="decimal" className="tabular-nums" value={addQty} onChange={(event) => setAddQty(event.target.value)} />}
                    </Field>
                  </div>
                  <Button type="button" variant="secondary" disabled={addItem === "" || saving > 0} onClick={() => void add()}>
                    <Plus className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
                    {t("add_button")}
                  </Button>
                </div>
              </section>
            ) : null}
          </>
        )}

        {waiting ? (
          <p role="status" className="rounded-xl bg-blue-50 px-4 py-3 text-base font-medium text-blue-800">
            {o("queued")}
          </p>
        ) : null}
        {editable ? (
          <div className="flex justify-end border-t border-neutral-200 pt-5">
            {/* handing in needs the connection and every draft on the server first */}
            <Button type="button" size="xl" disabled={saving > 0 || waiting} onClick={() => setReview(true)} className="min-w-64">
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
