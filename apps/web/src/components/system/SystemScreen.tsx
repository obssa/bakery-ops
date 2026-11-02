"use client";

import { useTranslations } from "next-intl";
import { useMemo, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { stampText } from "@/lib/dates";
import { useErrorText } from "@/lib/errors";
import { createSystemFeed } from "@/lib/live/system";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { backupState, drillState, flagRows, heartbeatState, wholeMb, type BackupState, type DrillState, type HeartbeatState, type SystemStatus } from "@/lib/system";
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { focusRing } from "../styles";
import { Alert, Badge, Button, PageHeader, Panel } from "../ui";

// Admin > System (step 5.5c, phase-5 plan 4.4; system.view, the admin): the health of the host the bakery runs on, polled every 30 s with
// an ETag (lib/live/system.ts). The scheduler's heartbeat, the backups and the weekly restore drill, the queue, the disk the receipt photos
// and the backups take, the tablets, the rollout switches of the server's settings, and the release. Read-only: nothing here starts a
// backup or changes a setting (those live on the server, docs/handbook/system-page.md says where). A machine identifier (the commit, a
// switch the page does not name yet) is marked translate="no"; everything a person reads is in their language.

type Tone = "green" | "amber" | "red" | "blue" | "neutral";

const HEARTBEAT_TONE: Record<HeartbeatState, Tone> = { running: "green", stopped: "red", never: "amber" };
const BACKUP_TONE: Record<BackupState, Tone> = { ok: "green", stale: "red", failed: "red", running: "blue", none: "neutral" };
const DRILL_TONE: Record<DrillState, Tone> = { ok: "green", old: "amber", failed: "red", running: "blue", not_configured: "neutral" };

export function SystemScreen() {
  const t = useTranslations("system");
  const errorText = useErrorText();
  const signedIn = useSession((s) => s.status === "signed-in");
  const feed = useMemo(() => (signedIn ? createSystemFeed() : null), [signedIn]);
  const snapshot = useLiveFeed(feed);
  const status = snapshot?.state.status ?? null;
  const now = useNow(15_000);

  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} />
      {snapshot?.error && snapshot.loaded ? (
        <div className="mb-4">
          <Alert>{t("stale")}</Alert>
        </div>
      ) : null}
      {status === null ? (
        snapshot?.error ? (
          <div className="space-y-3">
            <Alert>{errorText.message(snapshot.error)}</Alert>
            <Button variant="secondary" onClick={() => feed?.refresh()}>
              {t("retry")}
            </Button>
          </div>
        ) : (
          <p role="status" className="py-6 text-base text-neutral-600">
            {t("loading")}
          </p>
        )
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          <SchedulerPanel status={status} now={now} />
          <BackupsPanel status={status} now={now} />
          <QueuePanel status={status} />
          <DevicesPanel status={status} />
          <FlagsPanel status={status} />
          <ReleasePanel status={status} />
        </div>
      )}
    </>
  );
}

/** A label and its value, one row of a panel's list. */
function Row({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-neutral-100 py-2 first:border-t-0 first:pt-0">
      <dt className="text-base text-neutral-600">{label}</dt>
      <dd className="text-base font-medium text-neutral-900 tabular-nums">{children}</dd>
    </div>
  );
}

function SchedulerPanel({ status, now }: { status: SystemStatus; now: number }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const state = heartbeatState(status.scheduler, now);
  return (
    <div data-testid="system-scheduler">
      <Panel title={t("scheduler.title")} className="h-full">
        <p className="mb-3">
          <Badge tone={HEARTBEAT_TONE[state]}>{t(`scheduler.${state}`)}</Badge>
        </p>
        <dl>
          <Row label={t("scheduler.last_tick")}>{stampText(status.scheduler.last_tick_at, dates)}</Row>
        </dl>
        {state === "running" ? null : <p className="mt-3 text-sm text-neutral-600">{t("scheduler.help")}</p>}
      </Panel>
    </div>
  );
}

function BackupsPanel({ status, now }: { status: SystemStatus; now: number }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const { backups } = status;
  const state = backupState(backups, now);
  const drill = drillState(backups.drill, now);
  return (
    <div data-testid="system-backups" className="lg:row-span-2">
      <Panel title={t("backups.title")} className="h-full">
        <p className="mb-3">
          <Badge tone={BACKUP_TONE[state]}>{t(`backups.${state}`)}</Badge>
        </p>
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
        </dl>
        <div data-testid="system-drill" className="mt-5 border-t border-neutral-200 pt-4">
          <h3 className="mb-2 text-base font-semibold text-neutral-900">{t("drill.title")}</h3>
          <p className="mb-3">
            <Badge tone={DRILL_TONE[drill]}>{t(`drill.${drill}`)}</Badge>
          </p>
          <dl>
            <Row label={t("drill.last_at")}>{stampText(backups.drill.last_at, dates)}</Row>
          </dl>
          {drill === "not_configured" ? <p className="mt-3 text-sm text-neutral-600">{t("drill.help")}</p> : null}
        </div>
      </Panel>
    </div>
  );
}

function QueuePanel({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const { queue } = status;
  return (
    <div data-testid="system-queue">
      <Panel title={t("queue.title")} className="h-full">
        <dl>
          <Row label={t("queue.pending")}>{queue.pending}</Row>
          <Row label={t("queue.oldest")}>{queue.oldest_age_s === null ? "—" : t("queue.seconds", { seconds: queue.oldest_age_s })}</Row>
          <Row label={t("queue.failed_24h")}>{queue.failed_24h}</Row>
          <Row label={t("queue.failed_total")}>{queue.failed_total}</Row>
        </dl>
      </Panel>
    </div>
  );
}

function DevicesPanel({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const { devices } = status;
  return (
    <div data-testid="system-devices">
      <Panel title={t("devices.title")} className="h-full">
        <dl>
          <Row label={t("devices.enrolled")}>{devices.enrolled}</Row>
          <Row label={t("devices.seen_15m")}>{devices.seen_15m}</Row>
          <Row label={t("devices.revoked")}>{devices.revoked}</Row>
        </dl>
        <p className="mt-3">
          <Link href="/admin/people/devices" className={`text-base text-blue-700 underline ${focusRing}`}>
            {t("devices.manage")}
          </Link>
        </p>
      </Panel>
    </div>
  );
}

function FlagsPanel({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const rows = flagRows(status.flags);
  return (
    <div data-testid="system-flags">
      <Panel title={t("flags.title")} className="h-full">
        <dl>
          {rows.map(({ key, known, on }) => (
            <Row key={key} label={known ? t(`flags.${key}`) : <span translate="no">{key}</span>}>
              <Badge tone={on ? "green" : "neutral"}>{on ? t("flags.on") : t("flags.off")}</Badge>
            </Row>
          ))}
        </dl>
        <p className="mt-3 text-sm text-neutral-600">{t("flags.help")}</p>
      </Panel>
    </div>
  );
}

function ReleasePanel({ status }: { status: SystemStatus }) {
  const t = useTranslations("system");
  const dates = useDateFormat();
  const { app } = status;
  return (
    <div data-testid="system-release">
      <Panel title={t("release.title")} className="h-full">
        <dl>
          <Row label={t("release.commit")}>
            {app.commit === null ? t("release.no_commit") : <span translate="no">{app.commit.slice(0, 12)}</span>}
          </Row>
          <Row label={t("release.released_at")}>{stampText(app.released_at, dates)}</Row>
          <Row label={t("release.migrations")}>{app.migrations}</Row>
          <Row label={t("release.error_tracking")}>
            <Badge tone={status.error_tracking.enabled ? "green" : "neutral"}>{status.error_tracking.enabled ? t("flags.on") : t("flags.off")}</Badge>
          </Row>
        </dl>
      </Panel>
    </div>
  );
}
