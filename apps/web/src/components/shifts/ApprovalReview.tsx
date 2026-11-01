"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
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
import { addisClock } from "@/lib/shifts";
import { useActiveStation } from "@/lib/station";
import { useIsoDay } from "@/lib/useDates";
import { HistoryLink } from "../audit/HistoryLink";
import { Alert, Badge, Button, Field, Modal, PageHeader, Panel, Status, Textarea } from "../ui";
import { OnlineOnlyButton } from "../OnlineOnly";
import { focusRing } from "../styles";
import { CashSummaryView } from "./CashSummaryView";
import { ChainCard } from "./ChainCard";
import { itemName, reasonName } from "./CountLines";
import { STAGE_TONE } from "./CountPanel";
import { useKeys } from "./useKeys";

// The side-by-side approval (step 3.7b), /admin/shifts/review?count=<id>: expected, counted, the difference, what moved at the place
// since the count, the reason and the note of every line, then Approve, Co-sign or Reject (with a note, and the lines to look at again).
// A locked count shows its place in the chain and its fingerprint, and an admin may unlock it for a correction. The API decides who may
// do what and answers in both languages; the buttons only offer what the person is allowed to try.

const POLL_MS = 6000;

export function ApprovalReview() {
  const t = useTranslations("counts");
  const params = useSearchParams();
  const raw = params.get("count") ?? "";
  if (!/^\d+$/.test(raw)) {
    return (
      <div className="max-w-xl space-y-4">
        <PageHeader title={t("review_page")} />
        <Alert>{t("no_count")}</Alert>
        <BackLink />
      </div>
    );
  }
  return <Loaded countId={Number(raw)} />;
}

function BackLink() {
  const t = useTranslations("counts");
  return (
    <Link href="/admin/shifts" className={`inline-flex min-h-12 items-center rounded-md px-1 text-base font-medium text-blue-800 underline ${focusRing}`}>
      {t("back_to_queue")}
    </Link>
  );
}

type Dialog = "reject" | "unlock" | null;

function Loaded({ countId }: { countId: number }) {
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
      <div className="max-w-xl space-y-4">
        <PageHeader title={t("review_page")} />
        <Alert>{errorText.message(countQuery.error)}</Alert>
        <BackLink />
      </div>
    );
  }
  if (!count) {
    return (
      <p role="status" className="text-base text-neutral-600">
        {t("loading")}
      </p>
    );
  }

  const stage = stageOf(count);
  const place = count.location ? (locale === "am" ? count.location.name_am || count.location.name_en : count.location.name_en) : "";
  const station = shift ? (locale === "am" ? shift.station.name_am : shift.station.name_en) : "";
  const decided = count.status === "locked" || count.status === "rejected";

  return (
    <div className="space-y-6">
      <BackLink />
      <PageHeader title={t("count_number", { id: count.id })} intro={[station, place, shift ? t("shift_date", { date: isoDay(shift.date) }) : ""].filter(Boolean).join(" · ")} />

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-neutral-200 bg-white px-5 py-4">
        <Badge tone={STAGE_TONE[stage]}>{t(`stage_${stage}_badge`)}</Badge>
        <Badge tone="neutral">{t(count.type === "opening" ? "type_opening" : "type_closing")}</Badge>
        <span className="ml-auto">
          <HistoryLink kind="stock_count" id={count.id} />
        </span>
        {count.counted_by ? <p className="text-base text-neutral-700">{t("handed_in_by", { name: count.counted_by.name, time: addisClock(count.submitted_at) })}</p> : null}
        {count.cosigned_by ? <p className="text-base text-neutral-700">{t("cosigned_by", { name: count.cosigned_by.name })}</p> : null}
        {decided && count.decided_by ? (
          <p className="text-base text-neutral-700">
            {t(count.decision === "rejected" ? "rejected_by" : "approved_by", { name: count.decided_by.name })}
            {count.decision_note ? `: ${count.decision_note}` : ""}
          </p>
        ) : null}
      </div>

      {count.blocked_by_pending ? <Alert>{t("blocked_by_pending")}</Alert> : null}
      {count.cash ? (
        <Panel title={t("cash_side_title")}>
          <CashSummaryView count={count} cash={count.cash} />
        </Panel>
      ) : null}

      {count.cash && lines.length === 0 ? null : (
      <Panel title={t("side_title")}>
        {lines.length === 0 ? (
          <p className="text-base text-neutral-600">{t("no_lines")}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[56rem] border-collapse text-left">
              <caption className="sr-only">{t("side_caption")}</caption>
              <thead>
                <tr className="border-b border-neutral-300 text-sm text-neutral-700">
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_item")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_expected")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_counted")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_difference")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_moved")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("col_reason")}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-200">
                {lines.map((line) => {
                  const variance = line.variance_qty ?? varianceOf(line);
                  const sign = variance === null ? 0 : compareDecimal(variance, "0", 4);
                  const moved = line.moved_since !== null && compareDecimal(line.moved_since, "0", 4) !== 0 ? line.moved_since : null;
                  return (
                    <tr key={line.id} className={sign === 0 ? "text-neutral-600" : ""}>
                      <th scope="row" className="px-3 py-3 text-left align-top text-base font-medium text-neutral-900">
                        {itemName(line, locale)}
                        <span className="block text-sm font-normal text-neutral-600">{line.item.unit}</span>
                      </th>
                      <td className="px-3 py-3 align-top text-base">{trimDecimal(line.expected_qty ?? "0")}</td>
                      <td className="px-3 py-3 align-top text-base">{line.counted_qty === null ? "—" : trimDecimal(line.counted_qty)}</td>
                      <td className={`px-3 py-3 align-top text-base font-medium ${sign < 0 ? "text-red-700" : sign > 0 ? "text-amber-800" : ""}`}>
                        {signedQuantity(variance)}
                      </td>
                      <td className="px-3 py-3 align-top text-base">
                        {moved !== null ? <span className="font-medium text-amber-800">{signedQuantity(moved)}</span> : <span className="text-neutral-500">—</span>}
                      </td>
                      <td className="px-3 py-3 align-top text-base">
                        {line.reason ? <span className="font-medium">{reasonName(line.reason, locale)}</span> : <span className="text-neutral-500">—</span>}
                        {line.note ? <span className="block text-sm text-neutral-700">{line.note}</span> : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {lines.some((line) => line.moved_since !== null && compareDecimal(line.moved_since, "0", 4) !== 0) ? (
          <p className="mt-3 text-sm text-amber-900">{t("moved_hint")}</p>
        ) : null}
      </Panel>
      )}

      {count.status === "locked" && count.chain ? <ChainCard chain={count.chain} /> : null}

      {actions.approve || actions.cosign || actions.reject || actions.unlock ? (
        <Panel title={t("decide_title")}>
          <div className="space-y-4">
            {actions.approve || actions.cosign ? (
              <div className="max-w-xl">
                <Field label={t("decision_note")} optionalLabel={t("optional")}>
                  {(props) => <Textarea {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
                </Field>
              </div>
            ) : null}
            <div className="flex flex-wrap gap-3">
              {actions.approve ? (
                <OnlineOnlyButton type="button" disabled={busy !== null} onClick={() => void act("approve", (key) => approveCount(countId, note, key), t("done_approved"))}>
                  {busy === "approve" ? t("working") : t("approve")}
                </OnlineOnlyButton>
              ) : null}
              {actions.cosign ? (
                <OnlineOnlyButton type="button" disabled={busy !== null} onClick={() => void act("cosign", (key) => cosignCount(countId, note, key), t("done_cosigned"))}>
                  {busy === "cosign" ? t("working") : t("cosign")}
                </OnlineOnlyButton>
              ) : null}
              {actions.reject ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setDialog("reject")}>
                  {t("reject")}
                </Button>
              ) : null}
              {actions.unlock ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setDialog("unlock")}>
                  {t("unlock")}
                </Button>
              ) : null}
            </div>
          </div>
        </Panel>
      ) : null}

      <Status>{done}</Status>
      {failure && dialog === null ? <Alert>{errorText.message(failure)}</Alert> : null}

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
    </div>
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
