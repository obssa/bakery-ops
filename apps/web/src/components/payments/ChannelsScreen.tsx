"use client";

import { ArrowDown, ArrowUp, ChevronDown, Lock } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { useAllChannels, useCreateChannel, useRemoveChannel, useReorderChannels, useUpdateChannel } from "@/lib/api/adminPayments";
import { ApiError } from "@/lib/api/client";
import { useIdempotencyKey, uuid } from "@/lib/api/idempotency";
import type { PaymentChannel } from "@/lib/api/types";
import {
  changesOf,
  channelControls,
  channelIssues,
  createBody,
  draftOf,
  HINT_MAX,
  isCash,
  listedChannels,
  liveIds,
  moveIds,
  NAME_MAX,
  CODE_MAX,
  PATTERN_MAX,
  patternCheck,
  sampleCheck,
  type ChannelDraft,
  type ChannelIssue,
} from "@/lib/adminPayments";
import { useErrorText } from "@/lib/errors";
import { keepsKey } from "@/lib/paySheet";
import { useDateFormat } from "@/lib/useDates";
import { ACTIONS_CELL, CARD, FORM_GRID, TableCard } from "../admin/kit";
import { Alert, Button, Chip, Field, IconButton, PageHeader, Panel, Status, TextInput } from "../ui";
import { QueryBox } from "../inventory/shared";
import { addisStamp, PaymentsNav, useChannelName } from "./shared";
import { focusRing } from "../styles";

// Admin > Payments > Payment methods (step 4.5c; channels.manage, the admin): the ways the till takes money, in the till's order, with what
// each asks for (a receipt photo, a reference, a reference pattern, the hints the till shows). A method is added with a code typed once,
// edited, moved one place up or down (buttons, not drag only: PUT /payment-channels/order with every live id once), switched off (the till
// stops offering it) or removed (soft: its payments keep pointing at it, its code stays taken; it is listed after the live ones). The cash row
// is locked: it can only change places (the drawer count is built on it; the API answers 409 payments.channel.cash_locked to anything more).
// Every write carries its Idempotency-Key: the same one when the answer was lost, a new one after a refusal; a new method's client_uuid stays
// for the form's life, so a retry whose answer was lost gets the same method back instead of "code taken".
//
// Step 6.4c (fresh-counter.md 4.4): the title row with its Amharic twin, the section's tabs, the methods a table card (the place, the names,
// the code, what each asks for, its On or Off chip; the moves as arrow buttons named "Move up <name>" and "Move down <name>", then Edit,
// Switch off and Remove), the removed ones folded under it, and the form a white card in two columns from 1024 px.

const CHECK = "size-5 shrink-0 accent-brand-600";

export function ChannelsScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("adminPayments");
  const channels = useAllChannels();
  const [editing, setEditing] = useState<PaymentChannel | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const formRef = useRef<HTMLDivElement>(null);

  // an Edit below the fold brings the form into view
  useEffect(() => {
    if (editing) formRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [editing]);

  return (
    <>
      <PageHeader title={t("channels.title")} intro={t("channels.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <PaymentsNav />
        <QueryBox query={channels}>
          {(rows) => (
            <ChannelTable
              rows={rows}
              onEdit={(row) => {
                setSaved(null);
                setEditing(row);
              }}
            />
          )}
        </QueryBox>
        <div ref={formRef} className="scroll-mt-8">
          <ChannelForm
            key={editing?.id ?? "new"}
            editing={editing}
            saved={saved}
            onSaved={(name) => {
              setSaved(name);
              setEditing(null);
            }}
            onCancel={() => setEditing(null)}
          />
        </div>
      </div>
    </>
  );
}

function Rules({ channel }: { channel: PaymentChannel }) {
  const t = useTranslations("adminPayments");
  const parts: string[] = [];
  if (channel.requires_photo) parts.push(t("channels.rule_photo"));
  if (channel.requires_reference) parts.push(t("channels.rule_reference"));
  return (
    <span className="block space-y-0.5">
      <span className="block text-neutral-700">{parts.length ? parts.join(" · ") : t("channels.rule_none")}</span>
      {channel.reference_pattern ? (
        <span className="block font-mono text-[13px] text-neutral-600">{t("channels.rule_pattern", { pattern: channel.reference_pattern })}</span>
      ) : null}
    </span>
  );
}

function ChannelTable({ rows, onEdit }: { rows: PaymentChannel[]; onEdit: (row: PaymentChannel) => void }) {
  const t = useTranslations("adminPayments");
  const dates = useDateFormat();
  const name = useChannelName();
  const errorText = useErrorText();
  const reorder = useReorderChannels();
  const update = useUpdateChannel();
  const remove = useRemoveChannel();
  const orderKey = useIdempotencyKey();
  const switchKey = useIdempotencyKey();
  const removeKey = useIdempotencyKey();
  const [confirming, setConfirming] = useState<number | null>(null);
  const [failure, setFailure] = useState<unknown>(null);

  const listed = listedChannels(rows);
  const live = listed.filter((row) => row.deleted_at === null);
  const removed = listed.filter((row) => row.deleted_at !== null);
  const ids = liveIds(rows);
  const busy = reorder.isPending || update.isPending || remove.isPending;

  /** After a write: a success frees its key, a refusal too (the API would replay it); an unknown outcome keeps it for the retry. */
  function settle(keys: { done: () => void }) {
    return {
      onSuccess: () => {
        keys.done();
        setFailure(null);
      },
      onError: (error: unknown) => {
        if (!keepsKey(error)) keys.done();
        setFailure(error);
      },
    };
  }

  function move(row: PaymentChannel, by: -1 | 1) {
    const next = moveIds(ids, row.id, by);
    if (!next) return;
    reorder.mutate({ ids: next, idempotencyKey: orderKey.keyFor({ ids: next }) }, settle(orderKey));
  }

  function toggle(row: PaymentChannel) {
    const changes = { is_enabled: !row.is_enabled };
    update.mutate({ id: row.id, changes, idempotencyKey: switchKey.keyFor({ id: row.id, ...changes }) }, settle(switchKey));
  }

  function confirmRemove(row: PaymentChannel) {
    remove.mutate({ id: row.id, idempotencyKey: removeKey.keyFor({ remove: row.id }) }, {
      ...settle(removeKey),
      onSettled: () => setConfirming(null),
    });
  }

  return (
    <div className="flex flex-col gap-4">
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <TableCard caption={t("channels.list")} title={t("channels.list")} minWidth="min-w-[880px]">
        <thead>
          <tr>
            <th scope="col" className="text-right">
              {t("channels.col_position")}
            </th>
            <th scope="col">{t("channels.col_name")}</th>
            <th scope="col">{t("channels.col_other_name")}</th>
            <th scope="col">{t("channels.col_code")}</th>
            <th scope="col">{t("channels.col_rules")}</th>
            <th scope="col">{t("channels.col_status")}</th>
            <th scope="col">
              <span className="sr-only">{t("channels.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {live.map((row) => {
            const controls = channelControls(row);
            const at = ids.indexOf(row.id);
            const label = name(row);
            const other = row.name_am === label ? row.name_en : row.name_am;
            return (
              <tr key={row.id}>
                <td className="w-14 text-right tabular-nums text-neutral-600">{at >= 0 ? at + 1 : "—"}</td>
                <th scope="row" className="font-semibold text-neutral-900">
                  {label}
                </th>
                <td className="text-neutral-700" lang={row.name_am === label ? "en" : "am"}>
                  {other}
                </td>
                <td className="font-mono text-sm text-neutral-700">{row.code}</td>
                <td>
                  <Rules channel={row} />
                </td>
                <td>
                  {row.is_enabled ? (
                    <Chip tone="brand" dot>
                      {t("channels.on")}
                    </Chip>
                  ) : (
                    <Chip tone="neutral" dot>
                      {t("channels.off")}
                    </Chip>
                  )}
                </td>
                <td className={ACTIONS_CELL}>
                  {confirming === row.id ? (
                    <div className="ml-auto w-80 space-y-2 whitespace-normal py-1 text-left">
                      <p className="text-[15px] text-neutral-800">{t("channels.remove_ask", { name: label })}</p>
                      <div className="flex flex-wrap gap-2">
                        <Button variant="danger" size="md" compact disabled={busy} onClick={() => confirmRemove(row)} aria-label={t("channels.remove_confirm_named", { name: label })}>
                          {t("channels.remove_confirm")}
                        </Button>
                        <Button variant="secondary" size="md" compact onClick={() => setConfirming(null)} aria-label={t("channels.remove_keep_named", { name: label })}>
                          {t("channels.remove_keep")}
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <div className="flex items-center justify-end gap-1.5">
                      {controls.move ? (
                        <>
                          <IconButton label={t("channels.move_up_named", { name: label })} size="md" disabled={busy || at <= 0} onClick={() => move(row, -1)}>
                            <ArrowUp className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                          </IconButton>
                          <IconButton label={t("channels.move_down_named", { name: label })} size="md" disabled={busy || at < 0 || at >= ids.length - 1} onClick={() => move(row, 1)}>
                            <ArrowDown className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                          </IconButton>
                        </>
                      ) : null}
                      {controls.edit ? (
                        <Button variant="secondary" size="md" compact disabled={busy} onClick={() => onEdit(row)} aria-label={t("channels.edit_named", { name: label })}>
                          {t("channels.edit")}
                        </Button>
                      ) : null}
                      {controls.toggle ? (
                        <Button
                          variant="secondary"
                          size="md"
                          compact
                          disabled={busy}
                          onClick={() => toggle(row)}
                          aria-label={t(row.is_enabled ? "channels.switch_off_named" : "channels.switch_on_named", { name: label })}
                        >
                          {t(row.is_enabled ? "channels.switch_off" : "channels.switch_on")}
                        </Button>
                      ) : null}
                      {controls.remove ? (
                        <Button variant="danger" size="md" compact disabled={busy} onClick={() => setConfirming(row.id)} aria-label={t("channels.remove_named", { name: label })}>
                          {t("channels.remove")}
                        </Button>
                      ) : null}
                      {isCash(row) && !row.deleted_at ? (
                        <span className="inline-flex max-w-52 items-start gap-1.5 whitespace-normal pl-1 text-left text-[13px] text-neutral-600">
                          <Lock className="mt-0.5 size-4 shrink-0" strokeWidth={2} aria-hidden="true" />
                          {t("channels.cash_locked")}
                        </span>
                      ) : null}
                    </div>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </TableCard>
      {removed.length > 0 ? (
        <details className={`group ${CARD} overflow-hidden`}>
          <summary className={`flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-5 text-[15px] font-semibold text-neutral-900 hover:bg-neutral-50 [&::-webkit-details-marker]:hidden ${focusRing}`}>
            {t("channels.removed_title", { count: removed.length })}
            <ChevronDown className="size-[18px] shrink-0 text-neutral-600 motion-safe:transition-transform group-open:rotate-180" strokeWidth={2} aria-hidden="true" />
          </summary>
          <div className="border-t border-neutral-200">
            <p className="px-5 py-3 text-[13px] text-neutral-600">{t("channels.removed_intro")}</p>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] border-collapse text-left text-[15px] text-neutral-900 [&_tbody_td]:h-12 [&_tbody_td]:px-3 [&_tbody_th]:px-3 [&_tbody_tr]:border-t [&_tbody_tr]:border-neutral-200 [&_thead_th]:h-11 [&_thead_th]:px-3 [&_thead_th]:text-[13px] [&_thead_th]:font-semibold [&_thead_th]:uppercase [&_thead_th]:tracking-[0.08em] [&_thead_th]:text-neutral-600 [&_tr>*:first-child]:pl-5 [&_tr>*:last-child]:pr-5">
                <caption className="sr-only">{t("channels.removed_list")}</caption>
                <thead>
                  <tr className="border-t border-neutral-200">
                    <th scope="col">{t("channels.col_name")}</th>
                    <th scope="col">{t("channels.col_other_name")}</th>
                    <th scope="col">{t("channels.col_code")}</th>
                    <th scope="col">{t("channels.col_rules")}</th>
                    <th scope="col">{t("channels.col_removed")}</th>
                  </tr>
                </thead>
                <tbody>
                  {removed.map((row) => {
                    const label = name(row);
                    return (
                      <tr key={row.id}>
                        <th scope="row" className="font-semibold text-neutral-900">
                          {label}
                        </th>
                        <td className="text-neutral-700" lang={row.name_am === label ? "en" : "am"}>
                          {row.name_am === label ? row.name_en : row.name_am}
                        </td>
                        <td className="font-mono text-sm text-neutral-700">{row.code}</td>
                        <td>
                          <Rules channel={row} />
                        </td>
                        <td className="whitespace-nowrap py-2">
                          <Chip>{t("channels.removed")}</Chip>
                          <span className="mt-1 block text-[13px] tabular-nums text-neutral-600">{addisStamp(row.deleted_at, dates)}</span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </details>
      ) : null}
    </div>
  );
}

/** The reason ReferencePattern gave for a refused pattern (422 payments.channel.pattern_invalid {reason}), in the page's language. */
const PATTERN_REASONS = new Set(["too_long", "does_not_compile", "unsupported", "nested_quantifier", "backtracks"]);

/** A check said as it is typed: brand-50 when it reads well, amber when it does not, neutral before there is anything to check. */
const CHECK_LOOK = { good: "bg-brand-50 text-brand-700", bad: "bg-amber-50 text-amber-800", none: "bg-neutral-50 text-neutral-700" } as const;

function ChannelForm({ editing, saved, onSaved, onCancel }: { editing: PaymentChannel | null; saved: string | null; onSaved: (name: string) => void; onCancel: () => void }) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  const name = useChannelName();
  const create = useCreateChannel();
  const update = useUpdateChannel();
  const idempotency = useIdempotencyKey();
  // one client_uuid for the new method until it is made (ADR 0003): a lost answer's retry finds the same method
  const [clientUuid, setClientUuid] = useState(() => uuid());
  const [draft, setDraft] = useState<ChannelDraft>(() => draftOf(editing));
  const [sample, setSample] = useState("");
  const [issues, setIssues] = useState<ChannelIssue[]>([]);
  const [failure, setFailure] = useState<unknown>(null);
  const [unchanged, setUnchanged] = useState(false);
  const creating = editing === null;
  const set = <K extends keyof ChannelDraft>(key: K, value: ChannelDraft[K]) => setDraft((current) => ({ ...current, [key]: value }));
  const pending = create.isPending || update.isPending;

  function failed(error: unknown) {
    if (!keepsKey(error)) idempotency.done();
    setFailure(error);
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFailure(null);
    setUnchanged(false);
    const found = channelIssues(draft, creating);
    setIssues(found);
    if (found.length > 0) return;
    if (creating) {
      const body = createBody(draft, clientUuid);
      create.mutate(
        { body, idempotencyKey: idempotency.keyFor(body) },
        {
          onSuccess: (channel) => {
            idempotency.done();
            setClientUuid(uuid());
            setDraft(draftOf(null));
            setSample("");
            onSaved(name(channel));
          },
          onError: failed,
        },
      );
      return;
    }
    const changes = changesOf(draft, editing);
    if (Object.keys(changes).length === 0) {
      setUnchanged(true);
      return;
    }
    update.mutate(
      { id: editing.id, changes, idempotencyKey: idempotency.keyFor({ id: editing.id, ...changes }) },
      {
        onSuccess: (channel) => {
          idempotency.done();
          onSaved(name(channel));
        },
        onError: failed,
      },
    );
  }

  const issue = (which: ChannelIssue) => (issues.includes(which) ? t(`channels.issue_${which}`) : undefined);
  const apiError = failure instanceof ApiError ? failure : null;
  const fieldError = (field: string) => errorText.field(failure, field);
  const patternReason = apiError?.key === "payments.channel.pattern_invalid" && typeof apiError.body.reason === "string" && PATTERN_REASONS.has(apiError.body.reason) ? apiError.body.reason : null;
  const check = patternCheck(draft.reference_pattern);
  const tried = sampleCheck(draft.reference_pattern, sample);
  const title = creating ? t("channels.new") : t("channels.edit_title", { name: name(editing) });

  return (
    <Panel title={title}>
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        <div className={FORM_GRID}>
          <Field label={t("channels.code")} hint={creating ? t("channels.code_hint") : t("channels.code_fixed")} error={issue("code") ?? fieldError("code")}>
            {(props) => (
              <TextInput {...props} controlSize="md" className="font-mono" value={draft.code} maxLength={CODE_MAX} disabled={!creating} autoCapitalize="none" spellCheck={false} onChange={(event) => set("code", event.target.value)} />
            )}
          </Field>
          <div className="flex flex-col justify-center gap-1 lg:pt-6">
            <label className="flex min-h-11 items-center gap-3 text-[15px] text-neutral-900">
              <input type="checkbox" className={CHECK} checked={draft.requires_photo} onChange={(event) => set("requires_photo", event.target.checked)} />
              {t("channels.requires_photo")}
            </label>
            <label className="flex min-h-11 items-center gap-3 text-[15px] text-neutral-900">
              <input type="checkbox" className={CHECK} checked={draft.requires_reference} onChange={(event) => set("requires_reference", event.target.checked)} />
              {t("channels.requires_reference")}
            </label>
          </div>
          <Field label={t("channels.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
            {(props) => <TextInput {...props} controlSize="md" value={draft.name_en} maxLength={NAME_MAX} onChange={(event) => set("name_en", event.target.value)} />}
          </Field>
          <Field label={t("channels.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
            {(props) => <TextInput {...props} controlSize="md" lang="am" value={draft.name_am} maxLength={NAME_MAX} onChange={(event) => set("name_am", event.target.value)} />}
          </Field>
          <div className="space-y-2">
            <Field label={t("channels.pattern")} optionalLabel={t("optional")} hint={t.rich("channels.pattern_hint", { code: (chunks) => <code translate="no">{chunks}</code> })} error={issue("pattern_long") ?? (patternReason ? t(`channels.pattern_reason.${patternReason}`) : fieldError("reference_pattern"))}>
              {(props) => (
                <TextInput {...props} controlSize="md" className="font-mono" value={draft.reference_pattern} maxLength={PATTERN_MAX + 20} autoCapitalize="none" spellCheck={false} onChange={(event) => set("reference_pattern", event.target.value)} />
              )}
            </Field>
            <p role="status" aria-live="polite" aria-label={t("channels.pattern_check")} className={`rounded-xl px-3 py-2 text-[13px] font-medium ${CHECK_LOOK[check === "ok" ? "good" : check === "none" ? "none" : "bad"]}`}>
              {t(`channels.pattern_${check}`)}
            </p>
          </div>
          <div className="space-y-2">
            <Field label={t("channels.sample")} hint={t("channels.sample_hint")}>
              {(props) => <TextInput {...props} controlSize="md" className="font-mono" value={sample} maxLength={80} autoCapitalize="none" spellCheck={false} onChange={(event) => setSample(event.target.value)} />}
            </Field>
            <p
              role="status"
              aria-live="polite"
              aria-label={t("channels.sample_check")}
              className={tried === null ? "sr-only" : `rounded-xl px-3 py-2 text-[13px] font-medium ${CHECK_LOOK[tried === "match" ? "good" : "bad"]}`}
            >
              {tried === "match" ? t("channels.sample_match") : tried === "no_match" ? t("channels.sample_no_match") : null}
            </p>
          </div>
        </div>
        <fieldset className="space-y-3 border-t border-neutral-200 pt-5">
          <legend className="float-left mb-3 w-full text-[13px] text-neutral-600">{t("channels.hints_hint")}</legend>
          <div className={`clear-both ${FORM_GRID}`}>
            <Field label={t("channels.reference_hint_en")} optionalLabel={t("optional")} error={issue("reference_hint_pair") ?? fieldError("reference_hint_en")}>
              {(props) => <TextInput {...props} controlSize="md" value={draft.reference_hint_en} maxLength={HINT_MAX} onChange={(event) => set("reference_hint_en", event.target.value)} />}
            </Field>
            <Field label={t("channels.reference_hint_am")} optionalLabel={t("optional")} error={fieldError("reference_hint_am")}>
              {(props) => <TextInput {...props} controlSize="md" lang="am" value={draft.reference_hint_am} maxLength={HINT_MAX} onChange={(event) => set("reference_hint_am", event.target.value)} />}
            </Field>
            <Field label={t("channels.photo_hint_en")} optionalLabel={t("optional")} error={issue("photo_hint_pair") ?? fieldError("photo_hint_en")}>
              {(props) => <TextInput {...props} controlSize="md" value={draft.photo_hint_en} maxLength={HINT_MAX} onChange={(event) => set("photo_hint_en", event.target.value)} />}
            </Field>
            <Field label={t("channels.photo_hint_am")} optionalLabel={t("optional")} error={issue("hint_long") ?? fieldError("photo_hint_am")}>
              {(props) => <TextInput {...props} controlSize="md" lang="am" value={draft.photo_hint_am} maxLength={HINT_MAX} onChange={(event) => set("photo_hint_am", event.target.value)} />}
            </Field>
          </div>
        </fieldset>
        {failure && !(apiError && Object.keys(apiError.fieldErrors).length > 0) ? <Alert>{errorText.message(failure)}</Alert> : null}
        <Status>{unchanged ? t("channels.no_changes") : saved ? t("channels.saved", { name: saved }) : null}</Status>
        <div className="flex flex-wrap gap-2.5">
          <Button type="submit" size="md" disabled={pending}>
            {pending ? t("working") : creating ? t("channels.add") : t("channels.save")}
          </Button>
          {creating ? null : (
            <Button type="button" variant="secondary" size="md" onClick={onCancel}>
              {t("cancel")}
            </Button>
          )}
        </div>
      </form>
    </Panel>
  );
}
