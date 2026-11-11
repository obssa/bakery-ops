"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Link } from "@/i18n/navigation";
import { ApiError } from "@/lib/api/client";
import { approveCount, cosignCount, getCount, rejectCount, unlockCount } from "@/lib/api/counts";
import type { Count, CountLine, Locale, Shift } from "@/lib/api/types";
import { approvalActions, signedQuantity, stageOf, varianceOf } from "@/lib/counts";
import { compareDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { authed, useSession } from "@/lib/session";
import { useActiveStation } from "@/lib/station";
import { useClock, useIsoDay } from "@/lib/useDates";
import { CARD, Notice, TableCard } from "../admin/kit";
import { HistoryLink } from "../audit/HistoryLink";
import { Alert, Button, Chip, Field, HelpTip, Modal, PageHeader, Panel, Status, Textarea } from "../ui";
import { OnlineOnlyButton } from "../OnlineOnly";
import { buttonClass } from "../styles";
import { AdminShiftsNav } from "./admin/common";
import { CashSummaryView } from "./CashSummaryView";
import { ChainCard } from "./ChainCard";
import { itemName, reasonName } from "./CountLines";
import { STAGE_TONE } from "./CountPanel";
import { useKeys } from "./useKeys";

// The side-by-side approval (step 3.7b), /admin/shifts/review?count=<id>: expected, counted, the difference, what moved at the place
// since the count, the reason and the note of every line, then Approve, Co-sign or Reject (with a note, and the lines to look at again).
// A locked count shows its place in the chain and its fingerprint, and an admin may unlock it for a correction. The API decides who may
// do what and answers in both languages; the buttons only offer what the person is allowed to try.
//
// Step 6.4d (fresh-counter.md 4.4, the boards' title row, cards and table): the title row with its Amharic twin, the way back and the
// audit history as its actions, the Shifts sections under it; the count's facts a white card (its stage and type as chips); the lines a
// table card (the figures right-aligned in tabular numbers, a shortage red, a surplus amber); the decision a white card.

const POLL_MS = 6000;

export function ApprovalReview({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("counts");
  const params = useSearchParams();
  const raw = params.get("count") ?? "";
  if (!/^\d+$/.test(raw)) {
    return (
      <>
        <PageHeader title={t("review_page")} actions={<BackLink />} />
        <div className="flex max-w-2xl flex-col gap-6">
          <AdminShiftsNav />
          <Alert>{t("no_count")}</Alert>
        </div>
      </>
    );
  }
  return <Loaded countId={Number(raw)} titleAm={titleAm} />;
}

function BackLink() {
  const t = useTranslations("counts");
  return (
    <Link href="/admin/shifts" className={buttonClass({ variant: "ghost", size: "md" })}>
      <ArrowLeft className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      {t("back_to_queue")}
    </Link>
  );
}

type Dialog = "reject" | "unlock" | null;

function Loaded({ countId, titleAm }: { countId: number; titleAm?: string }) {
  const clock = useClock();
  const t = useTranslations("counts");
  const isoDay = useIsoDay();
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const client = useQueryClient();
  const keys = useKeys();
  const me = useSession((s) => s.me);
  const signedIn = useSession((s) => s.status === "signed-in");

  const countQuery = useQuery({ queryKey: ["count", countId], queryFn: () => getCount(countId), enabled: signedIn, refetchInterval: POLL_MS });
  const count = countQuery.data ?? null;
  const shiftQuery = useQuery({
    queryKey: ["shift", count?.shift_id],
    queryFn: () => authed<Shift>(`/shifts/${count?.shift_id}`),
    enabled: signedIn && count !== null,
  });
  const shift = shiftQuery.data ?? null;

  const [note, setNote] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [failure, setFailure] = useState<unknown>(null);
  const [done, setDone] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog>(null);

  // the approval's writes name the station of the count, as the station screens do
  const stationId = shift?.station.id ?? null;
  useEffect(() => {
    if (stationId === null) return;
    useActiveStation.getState().set(stationId);
    return () => useActiveStation.getState().set(null);
  }, [stationId]);

  const actions = useMemo(() => {
    if (!count || !me) return { approve: false, cosign: false, reject: false, unlock: false };
    return approvalActions(count, {
      userId: me.user.id,
      permissions: me.permissions,
      worksAtStation: shift !== null && (me.work_stations ?? []).some((station) => station.station_id === shift.station.id),
      requiresOverseer: shift?.station.requires_overseer ?? false,
    });
  }, [count, me, shift]);

  const lines = useMemo(() => {
    if (!count) return [];
    const rank = (line: CountLine) => {
      const variance = varianceOf(line);
      return variance !== null && compareDecimal(variance, "0", 4) !== 0 ? 0 : 1;
    };
    return [...count.lines].sort((a, b) => rank(a) - rank(b) || itemName(a, locale).localeCompare(itemName(b, locale)) || a.id - b.id);
  }, [count, locale]);

  async function act(name: string, call: (key: string) => Promise<Count | { recount: Count } | { revision: Count }>, message: string) {
    if (busy) return;
    const keyName = `${name}:${countId}`;
    setBusy(name);
    setFailure(null);
    setDone(null);
    try {
      await call(keys.keyFor(keyName));
      keys.done(keyName);
      setDialog(null);
      setNote("");
      setDone(message);
      void client.invalidateQueries({ queryKey: ["count", countId] });
      void client.invalidateQueries({ queryKey: ["shift", count?.shift_id] });
      void client.invalidateQueries({ queryKey: ["handover"] });
    } catch (error) {
      setFailure(error);
      if (!(error instanceof ApiError) || error.status !== 0) keys.done(keyName);
      // someone else may have decided first: look at the count as it is now
      void client.invalidateQueries({ queryKey: ["count", countId] });
    } finally {
      setBusy(null);
    }
  }

  if (countQuery.isError && !count) {
    return (
      <>
        <PageHeader title={t("review_page")} actions={<BackLink />} />
        <div className="flex max-w-2xl flex-col gap-6">
          <AdminShiftsNav />
          <Alert>{errorText.message(countQuery.error)}</Alert>
        </div>
      </>
    );
  }
  if (!count) {
    return (
      <p role="status" className="text-[15px] text-neutral-600">
        {t("loading")}
      </p>
    );
  }

  const stage = stageOf(count);
  const place = count.location ? (locale === "am" ? count.location.name_am || count.location.name_en : count.location.name_en) : "";
  const station = shift ? (locale === "am" ? shift.station.name_am : shift.station.name_en) : "";
  const decided = count.status === "locked" || count.status === "rejected";
  const anyMoved = lines.some((line) => line.moved_since !== null && compareDecimal(line.moved_since, "0", 4) !== 0);

  return (
    <>
      <PageHeader
        title={t("count_number", { id: count.id })}
        twin={titleAm ? { text: titleAm.replace("{id}", String(count.id)), lang: "am" } : undefined}
        intro={[station, place, shift ? t("shift_date", { date: isoDay(shift.date) }) : ""].filter(Boolean).join(" · ")}
        actions={
          <>
            <BackLink />
            <HistoryLink kind="stock_count" id={count.id} />
          </>
        }
      />
      <div className="flex flex-col gap-6">
        <AdminShiftsNav />

        <section aria-label={t("count_number", { id: count.id })} className={`${CARD} space-y-4 p-5`}>
          <p className="flex flex-wrap items-center gap-2">
            <Chip tone={STAGE_TONE[stage]} dot>
              {t(`stage_${stage}_badge`)}
            </Chip>
            {count.status === "awaiting_cosign" || count.status === "pending_approval" ? (
              <HelpTip label={t(count.status === "awaiting_cosign" ? "chip_cosign_label" : "chip_approval_label")}>
                {t(count.status === "awaiting_cosign" ? "chip_cosign_help" : "chip_approval_help")}
              </HelpTip>
            ) : null}
            <Chip tone="neutral">{t(count.type === "opening" ? "type_opening" : "type_closing")}</Chip>
          </p>
          {count.counted_by || count.cosigned_by || (decided && count.decided_by) ? (
            <div className="flex flex-col gap-1 text-[15px] text-neutral-700">
              {count.counted_by ? <p>{t("handed_in_by", { name: count.counted_by.name, time: clock(count.submitted_at) })}</p> : null}
              {count.cosigned_by ? <p>{t("cosigned_by", { name: count.cosigned_by.name })}</p> : null}
              {decided && count.decided_by ? (
                <p>
                  {t(count.decision === "rejected" ? "rejected_by" : "approved_by", { name: count.decided_by.name })}
                  {count.decision_note ? `: ${count.decision_note}` : ""}
                </p>
              ) : null}
            </div>
          ) : null}
        </section>

        {count.blocked_by_pending ? <Alert>{t("blocked_by_pending")}</Alert> : null}
        {count.cash ? (
          <Panel title={t("cash_side_title")}>
            <CashSummaryView count={count} cash={count.cash} />
          </Panel>
        ) : null}

        {count.cash && lines.length === 0 ? null : lines.length === 0 ? (
          <Panel title={t("side_title")}>
            <p className="text-[15px] text-neutral-600">{t("no_lines")}</p>
          </Panel>
        ) : (
          <TableCard caption={t("side_caption")} title={t("side_title")} intro={anyMoved ? t("moved_hint") : undefined} minWidth="min-w-[56rem]">
            <thead>
              <tr>
                <th scope="col">{t("col_item")}</th>
                <th scope="col" className="text-right">
                  {t("col_expected")}
                </th>
                <th scope="col" className="text-right">
                  {t("col_counted")}
                </th>
                <th scope="col" className="text-right">
                  {t("col_difference")}
                </th>
                <th scope="col" className="text-right">
                  {t("col_moved")}
                </th>
                <th scope="col">{t("col_reason")}</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line) => {
                const variance = line.variance_qty ?? varianceOf(line);
                const sign = variance === null ? 0 : compareDecimal(variance, "0", 4);
                const moved = line.moved_since !== null && compareDecimal(line.moved_since, "0", 4) !== 0 ? line.moved_since : null;
                return (
                  <tr key={line.id} className={sign === 0 ? "text-neutral-600" : ""}>
                    <th scope="row" className="text-left font-semibold text-neutral-900">
                      {itemName(line, locale)}
                      <span className="block text-[13px] font-normal text-neutral-600">{line.item.unit}</span>
                    </th>
                    <td className="text-right tabular-nums">{trimDecimal(line.expected_qty ?? "0")}</td>
                    <td className="text-right tabular-nums">{line.counted_qty === null ? "—" : trimDecimal(line.counted_qty)}</td>
                    <td className={`text-right font-semibold tabular-nums ${sign < 0 ? "text-red-700" : sign > 0 ? "text-amber-800" : ""}`}>{signedQuantity(variance)}</td>
                    <td className="text-right tabular-nums">
                      {moved !== null ? <span className="font-semibold text-amber-800">{signedQuantity(moved)}</span> : <span className="text-neutral-600">—</span>}
                    </td>
                    <td>
                      {line.reason ? <span className="font-medium text-neutral-900">{reasonName(line.reason, locale)}</span> : <span className="text-neutral-600">—</span>}
                      {line.note ? <span className="block text-[13px] text-neutral-700">{line.note}</span> : null}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </TableCard>
        )}

        {count.status === "locked" && count.chain ? <ChainCard chain={count.chain} /> : null}

        {actions.approve || actions.cosign || actions.reject || actions.unlock ? (
          <Panel title={t("decide_title")}>
            <div className="space-y-4">
              {actions.approve || actions.cosign ? (
                <Notice tone="blue">
                  <p className="font-semibold">{t(actions.approve ? "approve_note_title" : "cosign_note_title")}</p>
                  <p className="mt-1">{t(actions.approve ? "approve_note" : "cosign_note")}</p>
                </Notice>
              ) : null}
              {actions.approve || actions.cosign ? (
                <div className="max-w-xl">
                  <Field label={t("decision_note")} optionalLabel={t("optional")}>
                    {(props) => <Textarea {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
                  </Field>
                </div>
              ) : null}
              <div className="flex flex-wrap gap-2.5">
                {actions.approve ? (
                  <OnlineOnlyButton type="button" size="md" disabled={busy !== null} onClick={() => void act("approve", (key) => approveCount(countId, note, key), t("done_approved"))}>
                    {busy === "approve" ? t("working") : t("approve")}
                  </OnlineOnlyButton>
                ) : null}
                {actions.cosign ? (
                  <OnlineOnlyButton type="button" size="md" disabled={busy !== null} onClick={() => void act("cosign", (key) => cosignCount(countId, note, key), t("done_cosigned"))}>
                    {busy === "cosign" ? t("working") : t("cosign")}
                  </OnlineOnlyButton>
                ) : null}
                {actions.reject ? (
                  <Button type="button" variant="danger" size="md" disabled={busy !== null} onClick={() => setDialog("reject")}>
                    {t("reject")}
                  </Button>
                ) : null}
                {actions.unlock ? (
                  <Button type="button" variant="secondary" size="md" disabled={busy !== null} onClick={() => setDialog("unlock")}>
                    {t("unlock")}
                  </Button>
                ) : null}
              </div>
            </div>
          </Panel>
        ) : null}

        <Status>{done}</Status>
        {failure && dialog === null ? <Alert>{errorText.message(failure)}</Alert> : null}
      </div>

      {dialog === "reject" ? (
        <RejectDialog
          lines={lines}
          busy={busy === "reject"}
          failure={failure ? errorText.message(failure) : null}
          onClose={() => setDialog(null)}
          onReject={(text, ids) => void act("reject", (key) => rejectCount(countId, text, ids, key), t("done_rejected"))}
        />
      ) : null}
      {dialog === "unlock" ? (
        <UnlockDialog
          busy={busy === "unlock"}
          failure={failure ? errorText.message(failure) : null}
          onClose={() => setDialog(null)}
          onUnlock={(text) => void act("unlock", (key) => unlockCount(countId, text, key), t("done_unlocked"))}
        />
      ) : null}
    </>
  );
}

function RejectDialog({
  lines,
  busy,
  failure,
  onClose,
  onReject,
}: {
  lines: CountLine[];
  busy: boolean;
  failure: string | null;
  onClose: () => void;
  onReject: (note: string, lineIds: number[] | null) => void;
}) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const differing = lines.filter((line) => {
    const variance = line.variance_qty ?? varianceOf(line);
    return variance !== null && compareDecimal(variance, "0", 4) !== 0;
  });
  const [note, setNote] = useState("");
  const [picked, setPicked] = useState<Set<number>>(new Set(differing.map((line) => line.id)));

  function toggle(id: number) {
    setPicked((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <Modal label={t("reject_title")} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("reject_title")}</h2>
      <p className="text-base text-neutral-700">{t("reject_body")}</p>
      <Field label={t("reject_note")}>{(props) => <Textarea {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}</Field>
      {lines.length > 0 ? (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-neutral-800">{t("reject_lines")}</legend>
          <ul className="max-h-56 space-y-1 overflow-y-auto">
            {lines.map((line) => (
              <li key={line.id}>
                <label className="flex min-h-12 items-center gap-3 text-base text-neutral-900">
                  <input type="checkbox" className="size-5" checked={picked.has(line.id)} onChange={() => toggle(line.id)} />
                  {itemName(line, locale)}
                </label>
              </li>
            ))}
          </ul>
        </fieldset>
      ) : null}
      {failure ? <Alert>{failure}</Alert> : null}
      <div className="flex flex-wrap justify-end gap-3">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("cancel")}
        </Button>
        <OnlineOnlyButton type="button" disabled={busy || note.trim() === ""} onClick={() => onReject(note.trim(), picked.size === 0 ? null : [...picked])}>
          {busy ? t("working") : t("reject_send")}
        </OnlineOnlyButton>
      </div>
    </Modal>
  );
}

function UnlockDialog({ busy, failure, onClose, onUnlock }: { busy: boolean; failure: string | null; onClose: () => void; onUnlock: (reason: string) => void }) {
  const t = useTranslations("counts");
  const [reason, setReason] = useState("");
  return (
    <Modal label={t("unlock_title")} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("unlock_title")}</h2>
      <p className="text-base text-neutral-700">{t("unlock_body")}</p>
      <Field label={t("unlock_reason")}>{(props) => <Textarea {...props} value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />}</Field>
      {failure ? <Alert>{failure}</Alert> : null}
      <div className="flex flex-wrap justify-end gap-3">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("cancel")}
        </Button>
        <OnlineOnlyButton type="button" disabled={busy || reason.trim() === ""} onClick={() => onUnlock(reason.trim())}>
          {busy ? t("working") : t("unlock_send")}
        </OnlineOnlyButton>
      </div>
    </Modal>
  );
}
