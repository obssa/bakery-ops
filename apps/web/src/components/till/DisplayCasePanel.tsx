"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo, useRef, useState } from "react";
import { uuid } from "@/lib/api/idempotency";
import { requestProduction } from "@/lib/api/production";
import type { CaseRow, Locale } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { createProductionFeed } from "@/lib/live/production";
import { createCaseFeed, openProductIds } from "@/lib/live/stations";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { can, useSession } from "@/lib/session";
import { Alert, Badge, Button, Panel } from "../ui";

// The display-case panel of the till (/till): what the case holds, the ones running out first, and "Request production"
// for a product the bakery makes (one tap asks for the product's batch size). While a request for the product is open the
// button reads "Requested" and does nothing. Two feeds: GET /display-case (counts and flags) and the production board
// (which products have an open request), both behind an ETag.

function Row({
  row,
  requested,
  mayAsk,
  busy,
  onAsk,
  refused,
}: {
  row: CaseRow;
  requested: boolean;
  mayAsk: boolean;
  busy: boolean;
  onAsk: (row: CaseRow) => void;
  refused: unknown;
}) {
  const t = useTranslations("stations");
  const errorText = useErrorText();
  const locale = useLocale() as Locale;
  const name = locale === "am" ? row.item.name_am : row.item.name_en;
  const held = trimDecimal(row.reserved) !== "0";
  return (
    <li className="space-y-2 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="text-base font-medium text-neutral-900">{name}</p>
          <p className="text-sm text-neutral-700">
            {t("case_available", { qty: trimDecimal(row.available), unit: row.unit })}
            {held ? ` · ${t("case_reserved", { qty: trimDecimal(row.reserved) })}` : ""}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {row.state !== "ok" ? <Badge tone={row.state === "out" ? "red" : "amber"}>{t(row.state === "out" ? "state_out" : "state_low")}</Badge> : null}
          {row.producible && mayAsk ? (
            requested ? (
              <Button type="button" variant="secondary" disabled aria-label={t("requested_named", { name })}>
                {t("requested")}
              </Button>
            ) : (
              <Button type="button" disabled={busy} aria-label={t("request_production_of", { name })} onClick={() => onAsk(row)}>
                {busy ? t("working") : t("request_production")}
              </Button>
            )
          ) : null}
        </div>
      </div>
      {refused ? <Alert>{errorText.message(refused)}</Alert> : null}
    </li>
  );
}

export function DisplayCasePanel() {
  const t = useTranslations("stations");
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const mayAsk = can(me, "production.request");
  const mayLook = can(me, "production.view");
  const caseFeed = useMemo(() => createCaseFeed(), []);
  const board = useMemo(() => (mayLook ? createProductionFeed() : null), [mayLook]);
  const snapshot = useLiveFeed(caseFeed);
  const boardSnapshot = useLiveFeed(board);
  const open = useMemo(() => openProductIds(boardSnapshot?.state.requests ?? []), [boardSnapshot]);
  const [busy, setBusy] = useState<number | null>(null);
  const [refusal, setRefusal] = useState<{ id: number; error: unknown } | null>(null);
  // one logical write per product until it succeeds: a retry reuses the client UUID and the key (ADR 0003)
  const attempts = useRef(new Map<number, { clientUuid: string; key: string }>());

  async function ask(row: CaseRow) {
    const id = row.item.id;
    const attempt = attempts.current.get(id) ?? { clientUuid: uuid(), key: uuid() };
    attempts.current.set(id, attempt);
    setBusy(id);
    setRefusal(null);
    try {
      await requestProduction({ client_uuid: attempt.clientUuid, product_item_id: id }, attempt.key);
      attempts.current.delete(id);
    } catch (error) {
      setRefusal({ id, error });
    } finally {
      setBusy(null);
      // the board knows which requests are open: look at once, so "Requested" shows without waiting for the poll
      board?.refresh();
    }
  }

  return (
    <Panel title={t("case_title")}>
      {!snapshot?.loaded ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("case_loading")}
          </p>
        )
      ) : (
        <div className="space-y-3">
          {snapshot.error ? (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              {t("case_stale")}
            </p>
          ) : null}
          {snapshot.state.locationId === null ? (
            <p className="text-base text-neutral-700">{t("case_no_place")}</p>
          ) : snapshot.state.rows.length === 0 ? (
            <p className="text-base text-neutral-700">{t("case_empty")}</p>
          ) : (
            <ul className="divide-y divide-neutral-200">
              {snapshot.state.rows.map((row) => (
                <Row key={row.item.id} row={row} requested={open.has(row.item.id)} mayAsk={mayAsk} busy={busy === row.item.id} onAsk={(r) => void ask(r)} refused={refusal?.id === row.item.id ? refusal.error : null} />
              ))}
            </ul>
          )}
        </div>
      )}
    </Panel>
  );
}
