"use client";

import { useTranslations } from "next-intl";
import { useMemo, useState } from "react";
import { useRouter } from "@/i18n/navigation";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import type { Order, ShortfallLine } from "@/lib/api/types";
import { useCarts, type Refusal } from "@/lib/cart";
import { useErrorText } from "@/lib/errors";
import { cartSourceOf, sourceOfOrder, trayEntries, type Bilingual, type TrayEntry } from "@/lib/outbox/tray";
import { useOutbox } from "@/lib/outbox/store";
import { authed } from "@/lib/session";
import { focusRing } from "./styles";

// The conflict tray (step 5.2c; plan 4.2, ADR 0018): every write refused on sync stays here until someone acts on it, so nothing refused
// is dropped silently. Each entry says what was tried, the server's message in both languages (and the lines a shortfall names), what to
// do about it, and the actions the refusal allows: "Edit and resend" (the pad's or the till's send goes back to its cart), "Send again" (a
// new write with a new key and new client_uuids, once its cause is put right) and "Discard". Writes a device refusal holds on the tablet
// are counted here too. It replaces 5.2a's strip with its Dismiss button.

function Both({ text }: { text: Bilingual }) {
  return (
    <>
      {text.en ? (
        <span lang="en" className="block">
          {text.en}
        </span>
      ) : null}
      {text.am ? (
        <span lang="am" className="block">
          {text.am}
        </span>
      ) : null}
    </>
  );
}

const button = `min-h-12 rounded-md border px-4 font-medium ${focusRing}`;

/** The pad's (or till's) cart of a refused send, asked for from the cart store; the screen that opens next puts it in its cart. */
async function askForCart(entry: TrayEntry): Promise<void> {
  const { item } = entry;
  const body = item.conflict?.body ?? {};
  const inMemory = Object.values(useCarts.getState().carts).some((cart) => cart.orderUuid === item.subjectUuid && cart.lines.length > 0);
  if (inMemory) return;
  let source = cartSourceOf(item, uuid);
  if (!source && item.subjectId !== null) {
    const order = await authed<Order>(`/orders/${item.subjectId}`).catch(() => null);
    if (order) source = sourceOfOrder(order);
  }
  if (!source) return;
  const shortfall = source.serverOrderId !== null && Array.isArray(body.shortfall) ? (body.shortfall as ShortfallLine[]) : [];
  const error: Refusal | null =
    shortfall.length > 0 ? null : { key: entry.key, message_en: entry.message.en ?? entry.key, message_am: entry.message.am ?? entry.message.en ?? entry.key };
  useCarts.setState({ restore: { source, shortfall, error, resolves: [item.id] } });
}

function Entry({ entry }: { entry: TrayEntry }) {
  const t = useTranslations("outbox");
  const errorText = useErrorText();
  const router = useRouter();
  const discard = useOutbox((s) => s.discard);
  const resend = useOutbox((s) => s.resend);
  const [busy, setBusy] = useState(false);
  const { item } = entry;
  const said = entry.message.en || entry.message.am;

  async function edit() {
    setBusy(true);
    try {
      await askForCart(entry);
    } finally {
      setBusy(false);
    }
    router.push(item.screen === "till" ? "/till" : "/pad");
  }

  return (
    <li className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-base text-red-900">
      <div role="alert" className="space-y-2">
        <p className="font-semibold">{t("tray_what", { what: t(`kind_${item.kind}`) })}</p>
        <div className="space-y-1">{said ? <Both text={entry.message} /> : <span>{errorText.message(new ApiError(entry.status, item.conflict?.body ?? {}))}</span>}</div>
        {entry.details.length > 0 ? (
          <ul className="list-disc space-y-1 pl-5">
            {entry.details.map((detail, index) => (
              <li key={index}>
                <Both text={detail} />
              </li>
            ))}
          </ul>
        ) : null}
        {entry.held.length > 0 ? <p>{t("tray_held", { count: entry.held.length })}</p> : null}
        <p className="text-red-800">{t(`hint_${entry.reason}`)}</p>
        <div className="flex flex-wrap gap-2">
          {entry.actions.includes("edit") ? (
            <button type="button" disabled={busy} onClick={() => void edit()} className={`${button} border-red-300 bg-white hover:bg-red-100 disabled:opacity-60`}>
              {t("edit")}
            </button>
          ) : null}
          {entry.actions.includes("resend") ? (
            <button type="button" onClick={() => resend(item.id)} className={`${button} border-red-300 bg-white hover:bg-red-100`}>
              {t("resend")}
            </button>
          ) : null}
          <button type="button" onClick={() => discard(item.id)} className={`${button} border-transparent hover:bg-red-100`}>
            {t("discard")}
          </button>
        </div>
      </div>
    </li>
  );
}

export function ConflictTray() {
  const t = useTranslations("outbox");
  const queue = useOutbox((s) => s.queue);
  const held = useOutbox((s) => s.held);
  const waiting = useOutbox((s) => s.items.length);
  const entries = useMemo(() => trayEntries(queue), [queue]);
  const kept = held && waiting > 0;

  if (entries.length === 0 && !kept) return null;
  return (
    <section role="region" aria-label={t("tray_region")}>
      <ul className="flex flex-col gap-2">
        {kept ? (
          <li className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
            <div role="alert" className="space-y-1">
              <p className="font-semibold">{t("tray_device", { count: waiting })}</p>
              <Both text={{ en: held.en, am: held.am }} />
            </div>
          </li>
        ) : null}
        {entries.map((entry) => (
          <Entry key={entry.item.id} entry={entry} />
        ))}
      </ul>
    </section>
  );
}
