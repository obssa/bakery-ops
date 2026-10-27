"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
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
import { Alert, Badge, Button, Field, PageHeader, Panel, TextInput } from "../ui";
import { QueryBox } from "../inventory/shared";
import { TD, TH, useChannelName } from "./shared";

// Admin > Payments > Payment methods (step 4.5c; channels.manage, the admin): the ways the till takes money, in the till's order, with what
// each asks for (a receipt photo, a reference, a reference pattern, the hints the till shows). A method is added with a code typed once,
// edited, moved one place up or down (buttons, not drag only: PUT /payment-channels/order with every live id once), switched off (the till
// stops offering it) or removed (soft: its payments keep pointing at it, its code stays taken; it is listed after the live ones). The cash row
// is locked: it can only change places (the drawer count is built on it; the API answers 409 payments.channel.cash_locked to anything more).
// Every write carries its Idempotency-Key: the same one when the answer was lost, a new one after a refusal; a new method's client_uuid stays
// for the form's life, so a retry whose answer was lost gets the same method back instead of "code taken".

export function ChannelsScreen() {
  const t = useTranslations("adminPayments");
  const channels = useAllChannels();
  const [editing, setEditing] = useState<PaymentChannel | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  return (
    <>
      <PageHeader title={t("channels.title")} intro={t("channels.intro")} />
      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_28rem]">
        <Panel title={t("channels.list")}>
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
        </Panel>
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
    </>
  );
}

function Rules({ channel }: { channel: PaymentChannel }) {
  const t = useTranslations("adminPayments");
  const parts: string[] = [];
  if (channel.requires_photo) parts.push(t("channels.rule_photo"));
  if (channel.requires_reference) parts.push(t("channels.rule_reference"));
  return (
    <span className="block space-y-1">
      <span className="block">{parts.length ? parts.join(" · ") : t("channels.rule_none")}</span>
      {channel.reference_pattern ? (
        <span className="block font-mono text-sm text-neutral-700">{t("channels.rule_pattern", { pattern: channel.reference_pattern })}</span>
      ) : null}
    </span>
  );
}

function ChannelTable({ rows, onEdit }: { rows: PaymentChannel[]; onEdit: (row: PaymentChannel) => void }) {
  const t = useTranslations("adminPayments");
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
    <div className="space-y-3">
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("channels.list")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("channels.col_position")}</th>
              <th scope="col" className={TH}>{t("channels.col_name")}</th>
              <th scope="col" className={TH}>{t("channels.col_other_name")}</th>
              <th scope="col" className={TH}>{t("channels.col_code")}</th>
              <th scope="col" className={TH}>{t("channels.col_rules")}</th>
              <th scope="col" className={TH}>{t("channels.col_status")}</th>
              <th scope="col" className="py-2 font-medium">{t("channels.col_actions")}</th>
            </tr>
          </thead>
          <tbody>
            {listed.map((row) => {
              const controls = channelControls(row);
              const at = ids.indexOf(row.id);
              const label = name(row);
              const other = row.name_am === label ? row.name_en : row.name_am;
              return (
                <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                  <td className={`${TD} tabular-nums text-neutral-700`}>{at >= 0 ? at + 1 : "—"}</td>
                  <th scope="row" className={`${TD} font-medium text-neutral-900`}>
                    {label}
                  </th>
                  <td className={`${TD} text-neutral-700`}>{other}</td>
                  <td className={`${TD} font-mono text-sm`}>{row.code}</td>
                  <td className={TD}>
                    <Rules channel={row} />
                  </td>
                  <td className={TD}>
                    {row.deleted_at ? <Badge>{t("channels.removed")}</Badge> : row.is_enabled ? <Badge tone="green">{t("channels.on")}</Badge> : <Badge tone="amber">{t("channels.off")}</Badge>}
                  </td>
                  <td className="py-3 align-top">
                    {confirming === row.id ? (
                      <div className="max-w-sm space-y-2">
                        <p className="text-base text-neutral-800">{t("channels.remove_ask", { name: label })}</p>
                        <div className="flex flex-wrap gap-2">
                          <Button disabled={busy} onClick={() => confirmRemove(row)} aria-label={t("channels.remove_confirm_named", { name: label })} className="bg-red-700 hover:bg-red-800 disabled:bg-red-700/60">
                            {t("channels.remove_confirm")}
                          </Button>
                          <Button variant="secondary" onClick={() => setConfirming(null)} aria-label={t("channels.remove_keep_named", { name: label })}>
                            {t("channels.remove_keep")}
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <div className="flex flex-wrap items-center gap-2">
                        {controls.move ? (
                          <>
                            <Button variant="secondary" disabled={busy || at <= 0} onClick={() => move(row, -1)} aria-label={t("channels.move_up_named", { name: label })}>
                              {t("channels.move_up")}
                            </Button>
                            <Button variant="secondary" disabled={busy || at < 0 || at >= ids.length - 1} onClick={() => move(row, 1)} aria-label={t("channels.move_down_named", { name: label })}>
                              {t("channels.move_down")}
                            </Button>
                          </>
                        ) : null}
                        {controls.edit ? (
                          <Button variant="secondary" disabled={busy} onClick={() => onEdit(row)} aria-label={t("channels.edit_named", { name: label })}>
                            {t("channels.edit")}
                          </Button>
                        ) : null}
                        {controls.toggle ? (
                          <Button variant="secondary" disabled={busy} onClick={() => toggle(row)} aria-label={t(row.is_enabled ? "channels.switch_off_named" : "channels.switch_on_named", { name: label })}>
                            {t(row.is_enabled ? "channels.switch_off" : "channels.switch_on")}
                          </Button>
                        ) : null}
                        {controls.remove ? (
                          <Button variant="secondary" disabled={busy} onClick={() => setConfirming(row.id)} aria-label={t("channels.remove_named", { name: label })}>
                            {t("channels.remove")}
                          </Button>
                        ) : null}
                        {isCash(row) && !row.deleted_at ? <span className="text-sm text-neutral-600">{t("channels.cash_locked")}</span> : null}
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** The reason ReferencePattern gave for a refused pattern (422 payments.channel.pattern_invalid {reason}), in the page's language. */
const PATTERN_REASONS = new Set(["too_long", "does_not_compile", "unsupported", "nested_quantifier", "backtracks"]);

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
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <Field label={t("channels.code")} hint={creating ? t("channels.code_hint") : t("channels.code_fixed")} error={issue("code") ?? fieldError("code")}>
          {(props) => (
            <TextInput {...props} value={draft.code} maxLength={CODE_MAX} disabled={!creating} autoCapitalize="none" spellCheck={false} onChange={(event) => set("code", event.target.value)} />
          )}
        </Field>
        <Field label={t("channels.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
          {(props) => <TextInput {...props} value={draft.name_en} maxLength={NAME_MAX} onChange={(event) => set("name_en", event.target.value)} />}
        </Field>
        <Field label={t("channels.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
          {(props) => <TextInput {...props} lang="am" value={draft.name_am} maxLength={NAME_MAX} onChange={(event) => set("name_am", event.target.value)} />}
        </Field>
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={draft.requires_photo} onChange={(event) => set("requires_photo", event.target.checked)} />
          {t("channels.requires_photo")}
        </label>
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={draft.requires_reference} onChange={(event) => set("requires_reference", event.target.checked)} />
          {t("channels.requires_reference")}
        </label>
        <Field label={t("channels.pattern")} optionalLabel={t("optional")} hint={t("channels.pattern_hint")} error={issue("pattern_long") ?? (patternReason ? t(`channels.pattern_reason.${patternReason}`) : fieldError("reference_pattern"))}>
          {(props) => (
            <TextInput {...props} className="font-mono" value={draft.reference_pattern} maxLength={PATTERN_MAX + 20} autoCapitalize="none" spellCheck={false} onChange={(event) => set("reference_pattern", event.target.value)} />
          )}
        </Field>
        <p
          role="status"
          aria-live="polite"
          aria-label={t("channels.pattern_check")}
          className={`rounded-md px-3 py-2 text-sm ${check === "ok" ? "bg-green-50 text-green-900" : check === "none" ? "bg-neutral-50 text-neutral-700" : "bg-amber-50 text-amber-900"}`}
        >
          {t(`channels.pattern_${check === "too_long" ? "too_long" : check}`)}
        </p>
        <Field label={t("channels.sample")} hint={t("channels.sample_hint")}>
          {(props) => <TextInput {...props} className="font-mono" value={sample} maxLength={80} autoCapitalize="none" spellCheck={false} onChange={(event) => setSample(event.target.value)} />}
        </Field>
        <p role="status" aria-live="polite" aria-label={t("channels.sample_check")} className={tried === null ? "sr-only" : `rounded-md px-3 py-2 text-sm ${tried === "match" ? "bg-green-50 text-green-900" : "bg-amber-50 text-amber-900"}`}>
          {tried === "match" ? t("channels.sample_match") : tried === "no_match" ? t("channels.sample_no_match") : null}
        </p>
        <fieldset className="space-y-3">
          <legend className="text-sm text-neutral-600">{t("channels.hints_hint")}</legend>
          <Field label={t("channels.reference_hint_en")} optionalLabel={t("optional")} error={issue("reference_hint_pair") ?? fieldError("reference_hint_en")}>
            {(props) => <TextInput {...props} value={draft.reference_hint_en} maxLength={HINT_MAX} onChange={(event) => set("reference_hint_en", event.target.value)} />}
          </Field>
          <Field label={t("channels.reference_hint_am")} optionalLabel={t("optional")} error={fieldError("reference_hint_am")}>
            {(props) => <TextInput {...props} lang="am" value={draft.reference_hint_am} maxLength={HINT_MAX} onChange={(event) => set("reference_hint_am", event.target.value)} />}
          </Field>
          <Field label={t("channels.photo_hint_en")} optionalLabel={t("optional")} error={issue("photo_hint_pair") ?? fieldError("photo_hint_en")}>
            {(props) => <TextInput {...props} value={draft.photo_hint_en} maxLength={HINT_MAX} onChange={(event) => set("photo_hint_en", event.target.value)} />}
          </Field>
          <Field label={t("channels.photo_hint_am")} optionalLabel={t("optional")} error={issue("hint_long") ?? fieldError("photo_hint_am")}>
            {(props) => <TextInput {...props} lang="am" value={draft.photo_hint_am} maxLength={HINT_MAX} onChange={(event) => set("photo_hint_am", event.target.value)} />}
          </Field>
        </fieldset>
        {failure && !(apiError && Object.keys(apiError.fieldErrors).length > 0) ? <Alert>{errorText.message(failure)}</Alert> : null}
        <p role="status" aria-live="polite" className={saved || unchanged ? "rounded-md bg-green-50 px-4 py-3 text-base text-green-800" : "sr-only"}>
          {unchanged ? t("channels.no_changes") : saved ? t("channels.saved", { name: saved }) : null}
        </p>
        <div className="flex flex-wrap gap-3">
          <Button type="submit" disabled={pending}>
            {pending ? t("working") : creating ? t("channels.add") : t("channels.save")}
          </Button>
          {creating ? null : (
            <Button type="button" variant="secondary" onClick={onCancel}>
              {t("cancel")}
            </Button>
          )}
        </div>
      </form>
    </Panel>
  );
}
