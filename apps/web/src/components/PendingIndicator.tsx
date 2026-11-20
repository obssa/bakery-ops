"use client";

import { CloudUpload } from "lucide-react";
import { useTranslations } from "next-intl";
import { useMemo } from "react";
import { usePathname } from "@/i18n/navigation";
import { screenOf } from "@/lib/outbox/logic";
import { useOutbox } from "@/lib/outbox/store";
import { useSession } from "@/lib/session";
import { syncCounts } from "@/lib/syncState";
import { ConflictTray } from "./ConflictTray";
import { ICON_STROKE } from "./icons";

// The outbox in the shell: how many writes are waiting to reach the server from this screen (and from the others of this tablet,
// step 5.2a), and the conflict tray (step 5.2c): the writes refused on sync, each kept until someone edits and resends, sends again or
// discards it, so a refused write is never silent. Renders nothing when there is nothing to say. A band under the station top bar,
// whose sync chip sums the same state up in a word (step 6.2b, useSyncState).

/** What the outbox holds for the shell: writes waiting from this screen and from the others, refused ones, and a held queue. */
export function useSyncState() {
  const pathname = usePathname();
  const queue = useOutbox((s) => s.queue);
  // a write on the wire for the first time is not waiting yet (its screen shows it busy); after a failure it is
  const sendingId = useOutbox((s) => s.sendingId);
  const userId = useSession((s) => s.me?.user.id ?? null);
  // the person's own writes waiting, the other accounts' apart, and the refused writes as the tray lists them (lib/syncState.ts)
  const { here, elsewhere, others, conflicts } = useMemo(() => syncCounts(queue, sendingId, userId, screenOf(pathname)), [queue, sendingId, userId, pathname]);
  const held = useOutbox((s) => s.held !== null && s.items.length > 0);
  // the queue could not be written to the tablet (no IndexedDB, or a write failed): what waits lives in this page only (ADR 0029)
  const unsaved = useOutbox((s) => s.loaded && !s.durable && s.queue.length > 0);
  // the queue has not been read from the tablet yet: nothing can be said about it (the chip says it is checking, never "all sent")
  const loaded = useOutbox((s) => s.loaded);
  return { here, elsewhere, others, conflicts, held, unsaved, loaded };
}

export function PendingIndicator() {
  const t = useTranslations("outbox");
  const { here, elsewhere, others, conflicts, held, unsaved } = useSyncState();

  if (here === 0 && elsewhere === 0 && others === 0 && conflicts === 0 && !held && !unsaved) return null;
  return (
    <div role="region" aria-label={t("region")} className="border-b border-neutral-200 bg-neutral-50">
      <div className="flex flex-col gap-2 px-4 py-3 sm:px-6">
        {here > 0 || elsewhere > 0 ? (
          <p role="status" className="flex items-center gap-2 text-[15px] font-semibold text-blue-700">
            <CloudUpload className="size-5 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
            <span>
              {here > 0 ? t("pending", { count: here }) : null}
              {here > 0 && elsewhere > 0 ? " · " : null}
              {elsewhere > 0 ? t("pending_elsewhere", { count: elsewhere }) : null}
            </span>
          </p>
        ) : null}
        {others > 0 ? <p className="text-[15px] font-medium text-neutral-700">{t("pending_others", { count: others })}</p> : null}
        {unsaved ? (
          <p role="alert" className="text-[15px] font-semibold text-amber-900">
            {t("not_durable")}
          </p>
        ) : null}
        <ConflictTray />
      </div>
    </div>
  );
}
