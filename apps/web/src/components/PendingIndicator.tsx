"use client";

import { useTranslations } from "next-intl";
import { useMemo } from "react";
import { usePathname } from "@/i18n/navigation";
import { useErrorText } from "@/lib/errors";
import { pendingCounts, screenOf } from "@/lib/outbox/logic";
import { useOutbox } from "@/lib/outbox/store";
import { focusRing } from "./styles";

// The outbox in the shell: how many writes are waiting to reach the server from this screen (and from the others of this tablet,
// step 5.2a), and the ones the server refused (kept until dismissed, so a dropped write is never silent; the conflict tray with its
// actions is step 5.2c). Renders nothing when there is nothing to say.

export function PendingIndicator() {
  const t = useTranslations("outbox");
  const errorText = useErrorText();
  const pathname = usePathname();
  const queue = useOutbox((s) => s.queue);
  // a write on the wire for the first time is not waiting yet (its screen shows it busy); after a failure it is
  const sendingId = useOutbox((s) => s.sendingId);
  const { here, elsewhere } = useMemo(
    () => pendingCounts(queue.filter((item) => item.id !== sendingId || item.attempts > 0), screenOf(pathname)),
    [queue, sendingId, pathname],
  );
  // a refusal the pad shows on its own line (the shortfall) stays out of the strip
  const allFailures = useOutbox((s) => s.failures);
  const failures = useMemo(() => allFailures.filter((f) => !f.inline), [allFailures]);
  const dismiss = useOutbox((s) => s.dismissFailure);

  if (here === 0 && elsewhere === 0 && failures.length === 0) return null;
  return (
    <div role="region" aria-label={t("region")} className="border-b border-neutral-200 bg-neutral-50">
      <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-2">
        {here > 0 || elsewhere > 0 ? (
          <p role="status" className="text-base font-medium text-blue-900">
            {here > 0 ? t("pending", { count: here }) : null}
            {here > 0 && elsewhere > 0 ? " · " : null}
            {elsewhere > 0 ? t("pending_elsewhere", { count: elsewhere }) : null}
          </p>
        ) : null}
        {failures.map((failure) => (
          <div key={failure.id} role="alert" className="flex items-center justify-between gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-base text-red-800">
            <span>{t("failed", { what: t(`kind_${failure.kind}`), reason: errorText.message(failure.error) })}</span>
            <button type="button" onClick={() => dismiss(failure.id)} className={`min-h-12 rounded-md px-4 font-medium hover:bg-red-100 ${focusRing}`}>
              {t("dismiss")}
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
