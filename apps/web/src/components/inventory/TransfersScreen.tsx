"use client";

import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { Alert, Badge, Button, Field, Modal, PageHeader, Panel, Select, TextInput } from "@/components/ui";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useConfirmTransfer, useRejectTransfer, useTransfers, type Transfer, type TransferStatus } from "@/lib/api/inventory";
import { compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { QueryBox, qty, stamp, TD, TH, useNamer } from "./shared";

// Admin > Inventory > Transfers (step 2.3): stock a station asked the store for. The requested list is the work: confirm
// moves the stock (one ledger pair), reject needs a reason. Rows show what the source has available; an answer the API
// refuses (not enough stock, a person who does not work at the station) is shown under the row in the page language.
// A notification link lands here with ?transfer=<id>: all statuses are listed and that row is marked.

const STATUSES: readonly TransferStatus[] = ["requested", "confirmed", "rejected"];

type Filter = TransferStatus | "all";

export function TransfersScreen() {
  const t = useTranslations("inventory");
  const params = useSearchParams();
  const linked = /^\d+$/.test(params.get("transfer") ?? "") ? Number(params.get("transfer")) : null;
  const [filter, setFilter] = useState<Filter>(linked === null ? "requested" : "all");
  const transfers = useTransfers(filter === "all" ? null : filter);

  return (
    <>
      <PageHeader title={t("transfers.title")} intro={t("transfers.intro")} />
      <Panel title={t("transfers.list")}>
        <div className="mb-4 w-56">
          <Field label={t("transfers.status")}>
            {(props) => (
              <Select {...props} value={filter} onChange={(event) => setFilter(event.target.value as Filter)}>
                {STATUSES.map((value) => (
                  <option key={value} value={value}>
                    {t(`transfer_status.${value}`)}
                  </option>
                ))}
                <option value="all">{t("transfers.all")}</option>
              </Select>
            )}
          </Field>
        </div>
        <QueryBox query={transfers}>{(result) => <TransferTable rows={result.data} linked={linked} />}</QueryBox>
      </Panel>
    </>
  );
}

function TransferTable({ rows, linked }: { rows: Transfer[]; linked: number | null }) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const decide = can(me, "transfers.confirm");
  const confirm = useConfirmTransfer();
  const reject = useRejectTransfer();
  const confirmKey = useIdempotencyKey();
  const rejectKey = useIdempotencyKey();
  const [rejecting, setRejecting] = useState<Transfer | null>(null);
  const [reason, setReason] = useState("");
  // the refusal belongs to the row it came from
  const [refused, setRefused] = useState<{ id: number; error: unknown } | null>(null);
  const busy = confirm.isPending || reject.isPending;

  function confirmTransfer(transfer: Transfer) {
    setRefused(null);
    confirm.mutate(
      { id: transfer.id, idempotencyKey: confirmKey.keyFor({ id: transfer.id }) },
      { onSuccess: () => confirmKey.done(), onError: (error) => setRefused({ id: transfer.id, error }) },
    );
  }

  function rejectTransfer() {
    if (!rejecting) return;
    const trimmed = reason.trim();
    reject.mutate(
      { id: rejecting.id, reason: trimmed, idempotencyKey: rejectKey.keyFor({ id: rejecting.id, reason: trimmed }) },
      {
        onSuccess: () => {
          rejectKey.done();
          setRejecting(null);
          setReason("");
        },
      },
    );
  }

  return (
    <>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("transfers.list")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("transfers.col_item")}</th>
              <th scope="col" className={TH}>{t("transfers.col_qty")}</th>
              <th scope="col" className={TH}>{t("transfers.col_route")}</th>
              <th scope="col" className={TH}>{t("transfers.col_requested")}</th>
              <th scope="col" className={TH}>{t("transfers.col_status")}</th>
              <th scope="col" className="py-2 font-medium">{t("transfers.col_actions")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-6 text-neutral-600">{t("transfers.none")}</td>
              </tr>
            ) : (
              rows.flatMap((transfer) => {
                const short = transfer.source_available !== undefined && compareDecimal(transfer.source_available, transfer.qty) < 0;
                const itemName = name(transfer.item);
                const cells = (
                  <tr key={transfer.id} className={`border-b border-neutral-100 align-top last:border-0 ${linked === transfer.id ? "bg-blue-50" : ""}`}>
                    <th scope="row" className={`${TD} font-medium text-neutral-900`}>
                      {itemName}
                      {transfer.note ? <span className="block text-sm font-normal text-neutral-600">{transfer.note}</span> : null}
                    </th>
                    <td className={TD}>
                      {qty(transfer.qty, transfer.item.unit)}
                      {transfer.source_available !== undefined ? (
                        <span className={`block text-sm ${short ? "font-medium text-red-700" : "text-neutral-600"}`}>
                          {t(short ? "transfers.source_short" : "transfers.source_available", { qty: qty(transfer.source_available, transfer.item.unit) })}
                        </span>
                      ) : null}
                    </td>
                    <td className={TD}>{name(transfer.from_location)} → {name(transfer.to_location)}</td>
                    <td className={TD}>
                      {transfer.requested_by.name}
                      <span className="block text-sm text-neutral-600">{stamp(transfer.requested_at)}</span>
                    </td>
                    <td className={TD}>
                      <Badge tone={transfer.status === "confirmed" ? "green" : transfer.status === "rejected" ? "red" : "amber"}>{t(`transfer_status.${transfer.status}`)}</Badge>
                      {transfer.reject_reason ? <span className="block text-sm text-neutral-600">{transfer.reject_reason}</span> : null}
                    </td>
                    <td className="py-3">
                      {transfer.status === "requested" && decide ? (
                        <div className="flex flex-wrap gap-2">
                          <Button disabled={busy} aria-label={t("transfers.confirm_named", { item: itemName })} onClick={() => confirmTransfer(transfer)}>
                            {t("transfers.confirm")}
                          </Button>
                          <Button
                            variant="secondary"
                            disabled={busy}
                            aria-label={t("transfers.reject_named", { item: itemName })}
                            onClick={() => {
                              setRefused(null);
                              reject.reset();
                              setReason("");
                              setRejecting(transfer);
                            }}
                          >
                            {t("transfers.reject")}
                          </Button>
                        </div>
                      ) : null}
                    </td>
                  </tr>
                );
                return refused?.id === transfer.id
                  ? [
                      cells,
                      <tr key={`${transfer.id}-error`}>
                        <td colSpan={6} className="pb-3">
                          <Alert>{errorText.message(refused.error)}</Alert>
                        </td>
                      </tr>,
                    ]
                  : [cells];
              })
            )}
          </tbody>
        </table>
      </div>

      {rejecting ? (
        <Modal label={t("transfers.reject_title")} onClose={() => setRejecting(null)}>
          <h3 className="text-lg font-semibold text-neutral-900">{t("transfers.reject_title")}</h3>
          <p className="text-base text-neutral-700">{t("transfers.reject_about", { item: name(rejecting.item), qty: qty(rejecting.qty, rejecting.item.unit) })}</p>
          <Field label={t("transfers.reason")} hint={t("transfers.reason_hint")}>
            {(props) => <TextInput {...props} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} required />}
          </Field>
          {reject.isError ? <Alert>{errorText.message(reject.error)}</Alert> : null}
          <div className="flex gap-3">
            <Button disabled={reject.isPending || reason.trim() === ""} onClick={rejectTransfer}>
              {t("transfers.reject_confirm")}
            </Button>
            <Button variant="secondary" onClick={() => setRejecting(null)}>
              {t("cancel")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
