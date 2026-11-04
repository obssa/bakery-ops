"use client";

import { ChevronRight } from "lucide-react";
import { useTranslations } from "next-intl";
import { useId, useMemo, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { stampText } from "@/lib/dates";
import { useErrorText } from "@/lib/errors";
import { createSystemFeed } from "@/lib/live/system";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { backupState, drillState, flagRows, heartbeatState, wholeMb, type BackupState, type DrillState, type HeartbeatState, type SystemStatus } from "@/lib/system";
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { CARD, Notice, TEXT_LINK } from "../admin/kit";
import { Alert, Button, Chip, PageHeader, Skeleton, type Tone } from "../ui";

// Admin > System (step 5.5c, phase-5 plan 4.4; system.view, the admin): the health of the host the bakery runs on, polled every 30 s with
// an ETag (lib/live/system.ts). The scheduler's heartbeat, the backups and the weekly restore drill, the queue, the disk the receipt photos
// and the backups take, the tablets, the rollout switches of the server's settings, and the release. Read-only: nothing here starts a
// backup or changes a setting (those live on the server, docs/handbook/system-page.md says where). A machine identifier (the commit, a
// switch the page does not name yet) is marked translate="no"; everything a person reads is in their language.
//
// Step 6.4d (fresh-counter.md 4.4, the Admin board's "System" card): the title row with its Amharic twin; each part a white 18 px card,
// its state as a chip with a dot beside its title (brand teal when well, amber or red when not: green is money confirmed only), its
// figures on hairline rows, labels in neutral-600 and values right-aligned in tabular numbers. The backups card also says what the live
// product photos take ("Product photos", files.media_used_mb, step 6.1b).

const HEARTBEAT_TONE: Record<HeartbeatState, Tone> = { running: "brand", stopped: "red", never: "amber" };
const BACKUP_TONE: Record<BackupState, Tone> = { ok: "brand", stale: "red", failed: "red", running: "blue", none: "neutral" };
const DRILL_TONE: Record<DrillState, Tone> = { ok: "brand", old: "amber", failed: "red", running: "blue", not_configured: "neutral" };

export function SystemScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("system");
  const errorText = useErrorText();
  const signedIn = useSession((s) => s.status === "signed-in");
  const feed = useMemo(() => (signedIn ? createSystemFeed() : null), [signedIn]);
  const snapshot = useLiveFeed(feed);
  const status = snapshot?.state.status ?? null;
  const now = useNow(15_000);

  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        {snapshot?.error && snapshot.loaded ? <Notice tone="amber">{t("stale")}</Notice> : null}
        {status === null ? (
          snapshot?.error ? (
            <div className="space-y-3">
              <Alert>{errorText.message(snapshot.error)}</Alert>
              <Button variant="secondary" size="md" onClick={() => feed?.refresh()}>
                {t("retry")}
              </Button>
            </div>
          ) : (
            <div className="space-y-4">
              <p role="status" className="text-[15px] text-neutral-600">
                {t("loading")}
              </p>
              <div aria-hidden="true" className="grid gap-4 lg:grid-cols-2">
                {[0, 1, 2, 3].map((i) => (
                  <Skeleton key={i} className="h-44 rounded-[18px]" />
                ))}
              </div>
            </div>
          )
        ) : (
          <div className="grid items-start gap-4 lg:grid-cols-2">
            <div className="flex flex-col gap-4">
              <SchedulerCard status={status} now={now} />
              <QueueCard status={status} />
              <DevicesCard status={status} />
            </div>
            <div className="flex flex-col gap-4">
              <BackupsCard status={status} now={now} />
              <FlagsCard status={status} />
              <ReleaseCard status={status} />
            </div>
          </div>
        )}
      </div>
    </>
  );
}

/** A part of the page: a white card named by its 17 / 600 title, its state chip on the right. */
function SystemCard({ testId, title, chip, children }: { testId: string; title: string; chip?: { tone: Tone; text: string }; children: ReactNode }) {
  const id = useId();
  return (
    <section data-testid={testId} aria-labelledby={id} className={`${CARD} flex flex-col gap-3 p-5`}>
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
          {title}
        </h2>
        {chip ? (
          <Chip tone={chip.tone} dot>
            {chip.text}
          </Chip>
        ) : null}
      </div>
      {children}
    </section>
  );
}

/** A label and its value, one row of a card's list. */
function Row({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-neutral-100 py-2.5 first:border-t-0 first:pt-0">
      <dt className="text-[15px] text-neutral-600">{label}</dt>
      <dd className="text-right text-[15px] font-semibold tabular-nums text-neutral-900">{children}</dd>
    </div>
  );
}

function Help({ children }: { children: ReactNode }) {
  return <p className="text-[13px] text-neutral-600">{children}</p>;
}

function SchedulerCard({ status, now }: { status: SystemStatus; now: number }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const state = heartbeatState(status.scheduler, now);
  return (
    <SystemCard testId="system-scheduler" title={t("scheduler.title")} chip={{ tone: HEARTBEAT_TONE[state], text: t(`scheduler.${state}`) }}>
      <dl>
        <Row label={t("scheduler.last_tick")}>{stampText(status.scheduler.last_tick_at, dates)}</Row>
      </dl>
      {state === "running" ? null : <Help>{t("scheduler.help")}</Help>}
    </SystemCard>
  );
}

function BackupsCard({ status, now }: { status: SystemStatus; now: number }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const { backups } = status;
  const state = backupState(backups, now);
  const drill = drillState(backups.drill, now);
  const media = status.files.media_used_mb;
  return (
    <SystemCard testId="system-backups" title={t("backups.title")} chip={{ tone: BACKUP_TONE[state], text: t(`backups.${state}`) }}>
      <dl>
        <Row label={t("backups.last_ok")}>
          {backups.last_ok_at === null ? t("backups.never") : stampText(backups.last_ok_at, dates)}
          {backups.age_h === null ? null : <span className="ml-2 font-normal text-neutral-600">{t("backups.age", { hours: backups.age_h })}</span>}
        </Row>
        <Row label={t("backups.last_status")}>{backups.last_status === null ? "—" : t(`run.${backups.last_status}`)}</Row>
        <Row label={t("backups.size")}>{backups.bytes === null ? "—" : t("mb", { mb: wholeMb(backups.bytes) ?? 0 })}</Row>
        <Row label={t("backups.kept")}>{backups.kept}</Row>
        <Row label={t("backups.disk")}>{t("mb", { mb: backups.disk_used_mb })}</Row>
        <Row label={t("files.used")}>{t("mb", { mb: status.files.used_mb })}</Row>
        <Row label={t("files.media")}>{media === undefined ? "—" : t("mb", { mb: media })}</Row>
      </dl>
      <div data-testid="system-drill" className="mt-1 space-y-3 rounded-xl border border-neutral-200 p-4">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <h3 className="text-[15px] font-semibold text-neutral-900">{t("drill.title")}</h3>
          <Chip tone={DRILL_TONE[drill]} dot>
            {t(`drill.${drill}`)}
          </Chip>
        </div>
        <dl>
          <Row label={t("drill.last_at")}>{stampText(backups.drill.last_at, dates)}</Row>
        </dl>
        {drill === "not_configured" ? <Help>{t("drill.help")}</Help> : null}
      </div>
    </SystemCard>
  );
}

function QueueCard({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const { queue } = status;
  return (
    <SystemCard testId="system-queue" title={t("queue.title")}>
      <dl>
        <Row label={t("queue.pending")}>{queue.pending}</Row>
        <Row label={t("queue.oldest")}>{queue.oldest_age_s === null ? "—" : t("queue.seconds", { seconds: queue.oldest_age_s })}</Row>
        <Row label={t("queue.failed_24h")}>
          <span className={queue.failed_24h > 0 ? "text-red-700" : undefined}>{queue.failed_24h}</span>
        </Row>
        <Row label={t("queue.failed_total")}>{queue.failed_total}</Row>
      </dl>
    </SystemCard>
  );
}

function DevicesCard({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const { devices } = status;
  return (
    <SystemCard testId="system-devices" title={t("devices.title")}>
      <dl>
        <Row label={t("devices.enrolled")}>{devices.enrolled}</Row>
        <Row label={t("devices.seen_15m")}>{devices.seen_15m}</Row>
        <Row label={t("devices.revoked")}>{devices.revoked}</Row>
      </dl>
      <p>
        <Link href="/admin/people/devices" className={`inline-flex min-h-11 items-center gap-1 ${TEXT_LINK}`}>
          {t("devices.manage")}
          <ChevronRight className="size-4" strokeWidth={2} aria-hidden="true" />
        </Link>
      </p>
    </SystemCard>
  );
}

function FlagsCard({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const rows = flagRows(status.flags);
  return (
    <SystemCard testId="system-flags" title={t("flags.title")}>
      <dl>
        {rows.map(({ key, known, on }) => (
          <Row key={key} label={known ? t(`flags.${key}`) : <span translate="no">{key}</span>}>
            <Chip tone={on ? "brand" : "neutral"} dot>
              {on ? t("flags.on") : t("flags.off")}
            </Chip>
          </Row>
        ))}
      </dl>
      <Help>{t("flags.help")}</Help>
    </SystemCard>
  );
}

function ReleaseCard({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const { app } = status;
  return (
    <SystemCard testId="system-release" title={t("release.title")}>
      <dl>
        <Row label={t("release.commit")}>
          {app.commit === null ? t("release.no_commit") : (
            <span translate="no" className="font-mono text-sm">
              {app.commit.slice(0, 12)}
            </span>
          )}
        </Row>
        <Row label={t("release.released_at")}>{stampText(app.released_at, dates)}</Row>
        <Row label={t("release.migrations")}>{app.migrations}</Row>
        <Row label={t("release.error_tracking")}>
          <Chip tone={status.error_tracking.enabled ? "brand" : "neutral"} dot>
            {status.error_tracking.enabled ? t("flags.on") : t("flags.off")}
          </Chip>
        </Row>
      </dl>
    </SystemCard>
  );
}
