"use client";

import { useTranslations } from "next-intl";
import { useErrorText } from "@/lib/errors";
import { useOutbox } from "@/lib/outbox/store";
import { focusRing } from "./styles";

// The outbox in the shell: how many writes are waiting to reach the server, and the ones the server refused (kept
// until dismissed, so a dropped write is never silent). Renders nothing when there is nothing to say.

export function PendingIndicator() {
  const t = useTranslations("outbox");
  const errorText = useErrorText();
  const items = useOutbox((s) => s.items);
  const failures = useOutbox((s) => s.failures);
  const dismiss = useOutbox((s) => s.dismissFailure);

  if (items.length === 0 && failures.length === 0) return null;
  return (
    <div role="region" aria-label={t("region")} className="border-b border-neutral-200 bg-neutral-50">
      <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-2">
        {items.length > 0 ? (
          <p role="status" className="text-base font-medium text-blue-900">
            {t("pending", { count: items.length })}
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
