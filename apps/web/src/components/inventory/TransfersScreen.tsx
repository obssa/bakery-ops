"use client";

import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { ACTIONS_CELL, EmptyRow, TableCard, ToolbarSegments, Toolbar } from "@/components/admin/kit";
import { Alert, Button, Chip, Field, Modal, PageHeader, TextInput, type Tone } from "@/components/ui";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useConfirmTransfer, useRejectTransfer, useTransfers, type Transfer, type TransferStatus } from "@/lib/api/inventory";
import { compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { InventoryNav, QueryBox, qty, stamp, useNamer } from "./shared";

// Admin > Inventory > Transfers (step 2.3): stock a station asked the store for. The requested list is the work: confirm
// moves the stock (one ledger pair), reject needs a reason. Rows show what the source has available; an answer the API
// refuses (not enough stock, a person who does not work at the station) is shown under the row in the page language.
// A notification link lands here with ?transfer=<id>: all statuses are listed and that row is marked.
//
// Step 6.4c (fresh-counter.md 4.4, the CatalogList board): the title row with its Amharic twin, the section's tabs, the status as the
// toolbar's segments (Requested, Confirmed, Rejected, All), the transfers a table card (Requested amber, Confirmed brand, Rejected red; the
// linked row brand-50; Confirm the primary button and Reject the secondary one, named by the item as before).

const STATUSES: readonly TransferStatus[] = ["requested", "confirmed", "rejected"];

const STATUS_TONE: Record<TransferStatus, Tone> = { requested: "amber", confirmed: "brand", rejected: "red" };

type Filter = TransferStatus | "all";

export function TransfersScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("inventory");
  const params = useSearchParams();
  const linked = /^\d+$/.test(params.get("transfer") ?? "") ? Number(params.get("transfer")) : null;
  const [filter, setFilter] = useState<Filter>(linked === null ? "requested" : "all");
  const transfers = useTransfers(filter === "all" ? null : filter);

  return (
    <>
      <PageHeader title={t("transfers.title")} intro={t("transfers.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <InventoryNav />
        <Toolbar>
          <ToolbarSegments
            label={t("transfers.status")}
            value={filter}
            onChange={setFilter}
            segments={[...STATUSES.map((value) => ({ value, label: t(`transfer_status.${value}`) })), { value: "all" as const, label: t("transfers.all") }]}
          />
        </Toolbar>
        <QueryBox query={transfers}>{(result) => <TransferTable rows={result.data} linked={linked} />}</QueryBox>
      </div>
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
      <TableCard caption={t("transfers.list")} minWidth="min-w-[880px]">
        <thead>
          <tr>
            <th scope="col">{t("transfers.col_item")}</th>
            <th scope="col" className="text-right">
              {t("transfers.col_qty")}
            </th>
            <th scope="col">{t("transfers.col_route")}</th>
            <th scope="col">{t("transfers.col_requested")}</th>
            <th scope="col">{t("transfers.col_status")}</th>
            <th scope="col">
              <span className="sr-only">{t("transfers.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <EmptyRow span={6}>{t("transfers.none")}</EmptyRow>
          ) : (
            rows.flatMap((transfer) => {
              const short = transfer.source_available !== undefined && compareDecimal(transfer.source_available, transfer.qty) < 0;
              const itemName = name(transfer.item);
              const cells = (
                <tr key={transfer.id} className={linked === transfer.id ? "bg-brand-50 hover:bg-brand-50" : ""}>
                  <th scope="row" className="font-semibold text-neutral-900">
                    {itemName}
                    {transfer.note ? <span className="block text-[13px] font-normal text-neutral-600">{transfer.note}</span> : null}
                  </th>
                  <td className="whitespace-nowrap text-right">
                    <span className="font-semibold tabular-nums">{qty(transfer.qty, transfer.item.unit)}</span>
                    {transfer.source_available !== undefined ? (
                      <span className={`block text-[13px] tabular-nums ${short ? "font-semibold text-red-700" : "text-neutral-600"}`}>
                        {t(short ? "transfers.source_short" : "transfers.source_available", { qty: qty(transfer.source_available, transfer.item.unit) })}
                      </span>
                    ) : null}
                  </td>
                  <td className="text-neutral-700">
                    {name(transfer.from_location)} → {name(transfer.to_location)}
                  </td>
                  <td>
                    {transfer.requested_by.name}
                    <span className="block whitespace-nowrap text-[13px] tabular-nums text-neutral-600">{stamp(transfer.requested_at)}</span>
                  </td>
                  <td>
                    <Chip tone={STATUS_TONE[transfer.status]} dot>
                      {t(`transfer_status.${transfer.status}`)}
                    </Chip>
                    {transfer.reject_reason ? <span className="block pt-1 text-[13px] text-neutral-600">{transfer.reject_reason}</span> : null}
                  </td>
                  <td className={ACTIONS_CELL}>
                    {transfer.status === "requested" && decide ? (
                      <div className="flex justify-end gap-2">
                        <Button size="md" compact disabled={busy} aria-label={t("transfers.confirm_named", { item: itemName })} onClick={() => confirmTransfer(transfer)}>
                          {t("transfers.confirm")}
                        </Button>
                        <Button
                          variant="secondary"
                          size="md"
                          compact
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
      </TableCard>

      {rejecting ? (
        <Modal label={t("transfers.reject_title")} onClose={() => setRejecting(null)}>
          <h3 className="text-lg font-semibold text-neutral-900">{t("transfers.reject_title")}</h3>
          <p className="text-[15px] text-neutral-700">{t("transfers.reject_about", { item: name(rejecting.item), qty: qty(rejecting.qty, rejecting.item.unit) })}</p>
          <Field label={t("transfers.reason")} hint={t("transfers.reason_hint")}>
            {(props) => <TextInput {...props} controlSize="md" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} required />}
          </Field>
          {reject.isError ? <Alert>{errorText.message(reject.error)}</Alert> : null}
          <div className="flex gap-2.5">
            <Button variant="danger" size="md" disabled={reject.isPending || reason.trim() === ""} onClick={rejectTransfer}>
              {t("transfers.reject_confirm")}
            </Button>
            <Button variant="secondary" size="md" onClick={() => setRejecting(null)}>
              {t("cancel")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
