"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { closeShift, joinShift, leaveShift, cancelShift, startShift } from "@/lib/api/shifts";
import type { Locale, Shift } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { addisClock, barActions, onDuty, type BarMode } from "@/lib/shifts";
import { can, useSession } from "@/lib/session";
import { useShiftNotice } from "@/lib/station";
import { Alert, Badge, Button, Field, Modal, Select } from "../ui";
import { focusRing } from "../styles";
import { HandoverPanel } from "./HandoverPanel";
import { NoteForm } from "./Notes";
import type { ShiftStation } from "./useShiftStation";

// The shift bar (step 3.7a, phase-3 plan section 6 item 7): the strip at the top of every station screen (kitchen and bar displays,
// production, the till) that says whether a shift is running at the station, who is on it, and lets the person start, join, leave
// or close it. It reads the live shift (GET /stations/{station}/shifts/current, 5 s) and acts through the shift endpoints.
//
//   no shift   "Start shift" (count the stock first: the shift is `opening`) and "Start without counting" (open at once)
//   opening    the count is in progress: a link to the count screen (a placeholder until step 3.7b) and "Cancel start"
//   open       who is on, "Join shift" / "Leave shift", "Close shift" (after a confirmation: the station is frozen from then on)
//   counting   the closing count is in progress: the link to the count screen
//
// When the API refuses something because no shift is open (409 inventory.shift.required, payments.shift.required) the refusal is
// shown here in both languages, above the same Start buttons.

const BADGE: Record<BarMode, { tone: "amber" | "blue" | "green"; key: string }> = {
  idle: { tone: "amber", key: "badge_none" },
  opening: { tone: "blue", key: "badge_opening" },
  open: { tone: "green", key: "badge_open" },
  counting: { tone: "blue", key: "badge_counting" },
};

type ActionName = "start" | "start_carried" | "join" | "leave" | "close" | "cancel";

export function ShiftBar({ model }: { model: ShiftStation }) {
  const t = useTranslations("shifts");
  const tc = useTranslations("counts");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const notice = useShiftNotice((s) => s.notice);
  const { station, choices, choose, feed, snapshot, shift, loaded } = model;

  const [busy, setBusy] = useState<ActionName | null>(null);
  const [failure, setFailure] = useState<unknown>(null);
  const [confirmClose, setConfirmClose] = useState(false);
  const [showHandover, setShowHandover] = useState(false);
  const [noting, setNoting] = useState(false);

  // one key per logical action, reused by a retry of the same request and dropped when it succeeded (ADR 0003)
  const keys = useRef<Map<string, string>>(new Map());
  function keyFor(name: string): string {
    let key = keys.current.get(name);
    if (!key) {
      key = uuid();
      keys.current.set(name, key);
    }
    return key;
  }

  // a running shift answers the refusal "start a shift first"
  const status = shift?.status ?? null;
  useEffect(() => {
    if (status === "open") useShiftNotice.getState().clear();
  }, [status]);

  if (!station || !station.tracks_shifts || !feed) return null;
  // a person who may not read this station's shifts (403) has no bar; a network failure before the first answer shows the stale line
  if (!loaded && snapshot?.error instanceof ApiError && snapshot.error.status !== 0) return null;

  const userId = me?.user.id ?? 0;
  const powers = { canCount: can(me, "shifts.count"), canManage: can(me, "shifts.manage") };
  const actions = barActions(shift, userId, powers);
  const crew = onDuty(shift);
  const stationName = locale === "am" ? station.name_am : station.name_en;
  const showNotice = notice !== null && (notice.stationId === null || notice.stationId === station.station_id) && actions.mode !== "open";
  const noticeText = notice ? (locale === "am" ? notice.message_am || notice.message_en : notice.message_en || notice.message_am) : "";

  async function run(name: ActionName, keyName: string, call: (key: string) => Promise<Shift | null>) {
    if (busy) return;
    setBusy(name);
    setFailure(null);
    try {
      const next = await call(keyFor(keyName));
      keys.current.delete(keyName);
      useShiftNotice.getState().clear();
      feed?.amend((state) => ({ ...state, shift: next && ["opening", "open", "counting"].includes(next.status) ? next : null }));
      feed?.refresh();
    } catch (error) {
      setFailure(error);
      // a definite answer ends this attempt; only a lost connection retries under the same key
      if (!(error instanceof ApiError) || error.status !== 0) keys.current.delete(keyName);
      // someone else started the shift first, or closed it: look at once so the bar offers what is true now
      feed?.refresh();
    } finally {
      setBusy(null);
    }
  }

  const stationId = station.station_id;
  const start = (carry: boolean) => {
    const name = carry ? "start_carried" : "start";
    return run(name, name, async (key) => (await startShift(stationId, carry, key)).shift);
  };
  const act = (name: "join" | "leave" | "close" | "cancel", call: (id: number, key: string) => Promise<Shift>) => {
    const id = shift?.id;
    if (id === undefined) return Promise.resolve();
    return run(name, `${name}:${id}`, (key) => call(id, key));
  };

  const count = (
    <Link
      href={{ pathname: "/shifts/count", query: { shift: shift?.id ?? "" } }}
      className={`inline-flex min-h-12 items-center justify-center rounded-md bg-blue-700 px-5 text-base font-medium text-white hover:bg-blue-800 ${focusRing}`}
    >
      {t("open_count")}
    </Link>
  );

  let headline: ReactNode = null;
  let detail: ReactNode = null;
  switch (actions.mode) {
    case "idle":
      headline = t("idle");
      break;
    case "opening":
      headline = t("opening");
      detail = shift?.started_by ? t("opening_by", { name: shift.started_by.name, time: addisClock(shift.started_at) }) : null;
      break;
    case "open":
      headline = t("open");
      detail = t("open_since", { time: addisClock(shift?.opened_at ?? shift?.started_at) });
      break;
    case "counting":
      headline = t("counting");
      detail = shift?.closed_by ? t("counting_by", { name: shift.closed_by.name, time: addisClock(shift.closing_started_at) }) : null;
      break;
  }

  return (
    <section aria-label={t("region")} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="min-w-0 space-y-2">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            {loaded ? <Badge tone={BADGE[actions.mode].tone}>{t(BADGE[actions.mode].key)}</Badge> : null}
            <p className="text-lg font-semibold text-neutral-900">{loaded ? headline : t("checking")}</p>
            {loaded && detail ? <p className="text-base text-neutral-600">{detail}</p> : null}
            {loaded && shift && shift.notes_count > 0 ? <p className="text-base text-neutral-600">{t("notes", { count: shift.notes_count })}</p> : null}
          </div>
          {loaded && shift && (actions.mode === "open" || actions.mode === "opening") ? (
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-base text-neutral-600">{t("on_duty")}:</span>
              {crew.length === 0 ? (
                <span className="text-base text-neutral-600">{t("nobody")}</span>
              ) : (
                <ul className="flex flex-wrap gap-2" aria-label={t("on_duty")}>
                  {crew.map((row) => (
                    <li key={row.user?.id} className="rounded-full bg-neutral-100 px-3 py-1 text-base font-medium text-neutral-900">
                      {row.user?.name}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ) : null}
          {loaded && actions.mode === "idle" && !showNotice && powers.canCount ? <p className="max-w-2xl text-base text-neutral-600">{t("idle_hint")}</p> : null}
        </div>

        <div className="flex flex-wrap items-end gap-3">
          {choices.length > 1 ? (
            <div className="w-52">
              <Field label={t("station")}>
                {(props) => (
                  <Select {...props} value={station.station_id} onChange={(event) => choose(Number(event.target.value))}>
                    {choices.map((choice) => (
                      <option key={choice.station_id} value={choice.station_id}>
                        {locale === "am" ? choice.name_am : choice.name_en}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            </div>
          ) : null}
          {loaded ? (
            <>
              {actions.start ? (
                <Button type="button" disabled={busy !== null} onClick={() => void start(false)}>
                  {busy === "start" ? t("working") : t("start")}
                </Button>
              ) : null}
              {actions.startCarried ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => void start(true)}>
                  {busy === "start_carried" ? t("working") : t("start_carried")}
                </Button>
              ) : null}
              {actions.continueCount ? count : null}
              {actions.cancel ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => void act("cancel", cancelShift)}>
                  {t("cancel_start")}
                </Button>
              ) : null}
              {actions.join ? (
                <Button type="button" disabled={busy !== null} onClick={() => void act("join", joinShift)}>
                  {busy === "join" ? t("working") : t("join")}
                </Button>
              ) : null}
              {actions.leave ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => void act("leave", leaveShift)}>
                  {busy === "leave" ? t("working") : t("leave")}
                </Button>
              ) : null}
              {actions.close ? (
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setConfirmClose(true)}>
                  {t("close")}
                </Button>
              ) : null}
              {actions.mode === "idle" || actions.mode === "opening" ? (
                <Button type="button" variant="secondary" aria-expanded={showHandover} onClick={() => setShowHandover((open) => !open)}>
                  {showHandover ? tc("handover_hide") : tc("handover_show")}
                </Button>
              ) : null}
              {shift && actions.mode !== "idle" && powers.canCount ? (
                <Button type="button" variant="secondary" onClick={() => setNoting(true)}>
                  {tc("note_open")}
                </Button>
              ) : null}
            </>
          ) : null}
        </div>
      </div>

      {showNotice ? (
        <div role="alert" className="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          <p className="font-semibold">{t("needed", { station: stationName })}</p>
          {noticeText ? <p>{noticeText}</p> : null}
        </div>
      ) : null}
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      {snapshot?.error && loaded ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {t("stale")}
        </p>
      ) : null}

      {showHandover && (actions.mode === "idle" || actions.mode === "opening") ? <HandoverPanel stationId={station.station_id} /> : null}

      {noting && shift ? (
        <Modal label={tc("note_title")} onClose={() => setNoting(false)}>
          <h2 className="text-lg font-semibold text-neutral-900">{tc("note_title")}</h2>
          <NoteForm
            shiftId={shift.id}
            roster={shift.roster}
            onSaved={() => {
              setNoting(false);
              feed?.refresh();
            }}
          />
          <div className="flex justify-end">
            <Button type="button" variant="secondary" onClick={() => setNoting(false)}>
              {tc("cancel")}
            </Button>
          </div>
        </Modal>
      ) : null}

      {confirmClose ? (
        <Modal label={t("close_title")} onClose={() => setConfirmClose(false)}>
          <h2 className="text-lg font-semibold text-neutral-900">{t("close_title")}</h2>
          <p className="text-base text-neutral-700">{t("close_body")}</p>
          <div className="flex flex-wrap justify-end gap-3">
            <Button type="button" variant="secondary" onClick={() => setConfirmClose(false)}>
              {t("close_keep")}
            </Button>
            <Button
              type="button"
              disabled={busy !== null}
              onClick={() => {
                setConfirmClose(false);
                void act("close", async (id, key) => (await closeShift(id, key)).shift);
              }}
            >
              {t("close")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </section>
  );
}
