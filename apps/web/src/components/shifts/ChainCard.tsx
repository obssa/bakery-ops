"use client";

import { useTranslations } from "next-intl";
import type { CountChain } from "@/lib/api/types";
import { addisClock } from "@/lib/shifts";

/**
 * The lock of a count (step 3.7b): its place in the place's hash chain and its fingerprint, shown in full so it can be read out and
 * compared. The hash is what 3.4's chain stores; this screen only shows it.
 */
export function ChainCard({ chain }: { chain: CountChain }) {
  const t = useTranslations("counts");
  return (
    <section aria-label={t("chain_title")} className="space-y-2 rounded-md border border-green-200 bg-green-50 px-4 py-3">
      <h3 className="text-base font-semibold text-green-900">{t("chain_title")}</h3>
      <dl className="grid gap-x-6 gap-y-1 text-base sm:grid-cols-[max-content_1fr]">
        <dt className="text-neutral-700">{t("chain_seq")}</dt>
        <dd className="text-neutral-900">
          {chain.chain_seq} · {t("locked_at", { time: addisClock(chain.locked_at) })}
        </dd>
        <dt className="text-neutral-700">{t("chain_hash")}</dt>
        <dd>
          <code className="break-all text-sm text-neutral-900">{chain.payload_hash}</code>
        </dd>
        <dt className="text-neutral-700">{t("chain_previous")}</dt>
        <dd>{chain.previous_hash ? <code className="break-all text-sm text-neutral-700">{chain.previous_hash}</code> : <span className="text-neutral-600">{t("chain_first")}</span>}</dd>
      </dl>
    </section>
  );
}
