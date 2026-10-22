"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { Link } from "@/i18n/navigation";
import { readAllNotifications, readNotification, useNotificationChannels } from "@/lib/api/notifications";
import { uuid } from "@/lib/api/idempotency";
import type { AppNotification, Locale } from "@/lib/api/types";
import { resolveDeepLink } from "@/lib/deeplink";
import { useErrorText } from "@/lib/errors";
import { createInboxFeed, inboxOf, markedAllRead, markedRead } from "@/lib/live/notifications";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { enablePush, type PushOutcome } from "@/lib/push";
import { useSession } from "@/lib/session";
import { Alert, Badge, Button } from "./ui";
import { focusRing } from "./styles";

// The shell bell: the unread count and the inbox of the signed-in person, a LiveFeed over GET /notifications?since= (15 s
// while the tab is visible, 60 s hidden, ETag / 304). Lines are read one at a time or all at once through the API. A line
// links to the screen it is about when the web has that screen. "Enable notifications" is offered only when the API says
// push is on.

function when(iso: string, locale: Locale): string {
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return iso;
  return at.toLocaleString(locale === "am" ? "am-ET" : "en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

function Line({ line, locale, onRead, onFollow }: { line: AppNotification; locale: Locale; onRead: (line: AppNotification) => void; onFollow: (line: AppNotification) => void }) {
  const t = useTranslations("bell");
  const title = locale === "am" ? line.title_am : line.title_en;
  const body = locale === "am" ? line.body_am : line.body_en;
  const unread = line.read_at === null;
  const target = resolveDeepLink(line.deep_link);
  const content = (
    <>
      <span className="flex flex-wrap items-center gap-2">
        {unread ? <span aria-hidden="true" className="inline-block size-2.5 shrink-0 rounded-full bg-blue-700" /> : null}
        <span className={`text-base text-neutral-900 ${unread ? "font-semibold" : "font-normal"}`}>{title}</span>
        {line.severity === "warning" ? <Badge tone="amber">{t("severity_warning")}</Badge> : null}
        {line.severity === "critical" ? <Badge tone="red">{t("severity_critical")}</Badge> : null}
      </span>
      {body ? <span className="mt-0.5 block text-base text-neutral-700">{body}</span> : null}
      <time dateTime={line.created_at} className="mt-0.5 block text-sm text-neutral-600">
        {when(line.created_at, locale)}
      </time>
    </>
  );
  return (
    <li className="flex items-start gap-2 py-3">
      {target ? (
        <Link href={{ pathname: target.pathname, query: target.query }} onClick={() => onFollow(line)} className={`min-w-0 flex-1 rounded-md px-1 py-1 hover:bg-neutral-100 ${focusRing}`}>
          {content}
        </Link>
      ) : (
        <div className="min-w-0 flex-1 px-1 py-1">{content}</div>
      )}
      {unread ? (
        <button
          type="button"
          aria-label={t("mark_read", { title })}
          onClick={() => onRead(line)}
          className={`min-h-12 shrink-0 rounded-md border border-neutral-300 px-3 text-sm font-medium text-neutral-800 hover:bg-neutral-100 ${focusRing}`}
        >
          {t("mark_read_short")}
        </button>
      ) : null}
    </li>
  );
}

export function NotificationBell() {
  const t = useTranslations("bell");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const userId = useSession((s) => s.me?.user.id ?? null);
  const panelId = useId();
  const root = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [failure, setFailure] = useState<unknown>(null);
  const [push, setPush] = useState<PushOutcome | null>(null);
  const [busy, setBusy] = useState(false);

  // one inbox per signed-in person; a new person gets a new feed
  const feed = useMemo(() => (userId === null ? null : createInboxFeed()), [userId]);
  const snapshot = useLiveFeed(feed);
  const channels = useNotificationChannels(open);

  useEffect(() => {
    if (!open) return;
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setOpen(false);
        trigger.current?.focus();
      }
    }
    function onPointer(event: PointerEvent) {
      if (root.current && !root.current.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  const lines = useMemo(() => (snapshot ? inboxOf(snapshot.state) : []), [snapshot]);
  if (!feed) return null;
  const unread = snapshot?.state.unread ?? 0;

  async function markOne(line: AppNotification) {
    setFailure(null);
    const now = new Date().toISOString();
    try {
      await readNotification(line.id, uuid());
      feed?.amend((state) => markedRead(state, [line.id], now));
      feed?.refresh();
    } catch (error) {
      setFailure(error);
    }
  }

  async function markAll() {
    setFailure(null);
    const now = new Date().toISOString();
    try {
      // every unread line the person has now, drawn or not: a line that arrived since the last poll is cleared too
      await readAllNotifications(uuid());
      feed?.amend((state) => markedAllRead(state, now));
      feed?.refresh();
    } catch (error) {
      setFailure(error);
    }
  }

  async function enable() {
    const key = channels.data?.push.public_key;
    if (!key) return;
    setBusy(true);
    setFailure(null);
    try {
      const outcome = await enablePush(key);
      setPush(outcome);
      if (outcome === "subscribed") await channels.refetch();
    } catch (error) {
      setFailure(error);
    } finally {
      setBusy(false);
    }
  }

  const offerPush = !!channels.data?.push.enabled && !channels.data.push.subscribed && push !== "subscribed";
  const pushOn = !!channels.data?.push.enabled && (channels.data.push.subscribed || push === "subscribed");

  return (
    <div ref={root} className="relative">
      <button
        ref={trigger}
        type="button"
        aria-label={unread > 0 ? t("bell_unread", { count: unread }) : t("bell")}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        onClick={() => setOpen((v) => !v)}
        className={`relative inline-flex min-h-12 min-w-12 items-center justify-center rounded-md border border-neutral-300 bg-white px-3 text-neutral-800 hover:bg-neutral-100 ${focusRing}`}
      >
        <svg aria-hidden="true" viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9Z" />
          <path d="M10 19a2 2 0 0 0 4 0" />
        </svg>
        {unread > 0 ? (
          <span aria-hidden="true" className="ml-1.5 inline-flex min-w-6 items-center justify-center rounded-full bg-red-700 px-1.5 text-sm font-semibold leading-6 text-white">
            {unread > 99 ? "99+" : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <section id={panelId} aria-label={t("title")} className="absolute right-0 z-30 mt-2 w-[min(26rem,calc(100vw-2rem))] rounded-lg border border-neutral-200 bg-white p-4 shadow-xl">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-lg font-semibold text-neutral-900">{t("title")}</h2>
            <div className="flex gap-2">
              {unread > 0 ? (
                <Button type="button" variant="secondary" onClick={() => void markAll()}>
                  {t("mark_all")}
                </Button>
              ) : null}
              <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                {t("close")}
              </Button>
            </div>
          </div>

          {failure ? (
            <div className="mt-3">
              <Alert>{errorText.message(failure)}</Alert>
            </div>
          ) : null}
          {snapshot?.error ? (
            <p role="status" className="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-base text-amber-900">
              {t("stale")}
            </p>
          ) : null}

          {!snapshot?.loaded ? (
            <p role="status" className="mt-3 text-base text-neutral-600">
              {t("loading")}
            </p>
          ) : lines.length === 0 ? (
            <p className="mt-3 text-base text-neutral-600">{t("empty")}</p>
          ) : (
            <ul className="mt-2 max-h-[60vh] divide-y divide-neutral-200 overflow-y-auto">
              {lines.map((line) => (
                <Line
                  key={line.id}
                  line={line}
                  locale={locale}
                  onRead={(l) => void markOne(l)}
                  onFollow={(l) => {
                    setOpen(false);
                    if (l.read_at === null) void markOne(l);
                  }}
                />
              ))}
            </ul>
          )}

          {offerPush || push === "denied" || push === "unsupported" || pushOn ? (
            <div className="mt-3 space-y-2 border-t border-neutral-200 pt-3">
              {offerPush ? (
                <Button type="button" variant="secondary" disabled={busy} onClick={() => void enable()}>
                  {t("enable")}
                </Button>
              ) : null}
              {pushOn ? <p className="text-base text-neutral-700">{t("enabled")}</p> : null}
              {push === "denied" ? (
                <p role="status" className="text-base text-neutral-700">
                  {t("enable_denied")}
                </p>
              ) : null}
              {push === "unsupported" ? (
                <p role="status" className="text-base text-neutral-700">
                  {t("enable_unsupported")}
                </p>
              ) : null}
            </div>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}
