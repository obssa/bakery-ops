"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { getShiftCounts, getVarianceReasons } from "@/lib/api/counts";
import type { Count } from "@/lib/api/types";
import { currentCounts } from "@/lib/counts";
import { useErrorText } from "@/lib/errors";
import { useSession } from "@/lib/session";
import { Alert, Button, Modal } from "../ui";
import { CashDeclaration } from "./CashDeclaration";
import { CashSummaryView } from "./CashSummaryView";

// The drawer's opening declaration from the shift bar (step 3.7d), next to "Open the count": the cashier counts the notes and coins in the
// drawer where they stand, without leaving the till. The same declaration the count screen shows; it is handed in from the count screen
// ("Review and submit"), where the stock places of the till (if any) are counted too.

export function DrawerDialog({ shiftId, onClose }: { shiftId: number; onClose: () => void }) {
  const t = useTranslations("shifts");
  const errorText = useErrorText();
  const client = useQueryClient();
  const signedIn = useSession((s) => s.status === "signed-in");
  const countsQuery = useQuery({ queryKey: ["shift-counts", shiftId], queryFn: () => getShiftCounts(shiftId), enabled: signedIn, refetchInterval: 6000 });
  const reasonsQuery = useQuery({ queryKey: ["variance-reasons"], queryFn: getVarianceReasons, enabled: signedIn, staleTime: 60_000 });

  const drawer = currentCounts(countsQuery.data ?? []).find((count) => count.cash !== null && count.type === "opening") ?? null;

  function replace(next: Count) {
    client.setQueryData<Count[]>(["shift-counts", shiftId], (list) => [...(list ?? []).filter((count) => count.id !== next.id), next].sort((a, b) => a.id - b.id));
    void client.invalidateQueries({ queryKey: ["shift-counts", shiftId] });
  }

  return (
    <Modal label={t("drawer_title")} size="lg" onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("drawer_title")}</h2>
      <p className="text-base text-neutral-700">{t("drawer_intro")}</p>
      {countsQuery.isError && !countsQuery.data ? <Alert>{errorText.message(countsQuery.error)}</Alert> : null}
      {!countsQuery.data && !countsQuery.isError ? <p className="text-base text-neutral-600">{t("checking")}</p> : null}
      {countsQuery.data && !drawer ? <p className="text-base text-neutral-700">{t("drawer_none")}</p> : null}
      {drawer?.cash ? (
        drawer.status === "draft" ? (
          <CashDeclaration count={drawer} cash={drawer.cash} reasons={reasonsQuery.data ?? []} onChange={replace} />
        ) : (
          <CashSummaryView count={drawer} cash={drawer.cash} />
        )
      ) : null}
      <div className="flex justify-end">
        <Button type="button" onClick={onClose}>
          {t("drawer_done")}
        </Button>
      </div>
    </Modal>
  );
}
