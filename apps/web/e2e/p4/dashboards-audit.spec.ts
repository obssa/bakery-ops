import { expect, test, type Locator } from "@playwright/test";
import { uuid } from "../support/api";
import { requireAdmin, run } from "../support/env";
import { openAs } from "../support/pad";
import { buildRefundFixture, closedCashOrder, retireRefundProducts } from "../support/refunds";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7), step 4.5g: the audit screen shows this run's refund with
// its approver. The run sells its own order (a display-case cake, a burger made to order, a water that cannot be refunded) and closes it
// in cash; the cashier asks for the refund with the manager's PIN (POST /orders/{order}/refund, step 4.3d). Its one `order.refund` row
// (subject none, actor the cashier) is found by the run's reason; the admin opens it on the audit screen: the entry's context names the
// order, the amount, the reason and the approver by name (4.5f's drawer: dialog "Audit entry {id}", the "Context" table).

interface AuditListRow {
  id: number;
  action: string;
  actor: { id: number | null; name: string | null };
  summary: { reason: string | null };
}

interface AuditEntry {
  id: number;
  action: string;
  context: Record<string, unknown> | null;
}

/** A row of a table by its row header (the inner locator is the page's: it is matched inside each row). */
function rowOf(table: Locator, name: string): Locator {
  return table.getByRole("row").filter({ has: table.page().getByRole("rowheader", { name, exact: true }) });
}

test.describe("dashboards-audit", () => {
  test("the audit screen shows this run's refund with its approver @live", async ({ page }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildRefundFixture(credentials);
    try {
      const order = await closedCashOrder(fixture);
      const reason = `E2E audit refund ${run}`;
      const refund = await fixture.cashier.api.post<{ refund: { id: number; amount: string } }>(`/orders/${order.id}/refund`, {
        client_uuid: uuid(),
        reason,
        pin: fixture.manager.pin,
      });
      expect(refund.refund.amount).toBe("150.00");

      const listed = await fixture.admin.get<{ data: AuditListRow[] }>("/audit-log", { action: "order.refund" });
      const rows = listed.data.filter((r) => r.summary.reason === reason);
      expect(rows).toHaveLength(1);
      const [row] = rows;
      expect(row.action).toBe("order.refund");
      expect(row.actor.id).toBe(fixture.cashier.id);
      const entry = await fixture.admin.get<AuditEntry>(`/audit-log/${row.id}`);
      expect(entry.context?.order_id).toBe(order.id);
      expect(entry.context?.refund_id).toBe(refund.refund.id);
      expect(entry.context?.approved_by_user_id).toBe(fixture.manager.id);

      // the list filtered to the refunds, the run's row by its reason, then its entry
      await openAs(page, fixture.admin, "en/admin/audit/?action=order.refund");
      const log = page.getByRole("table", { name: "Audit log", exact: true });
      const listedRow = log.getByRole("row").filter({ hasText: reason });
      await expect(listedRow).toHaveCount(1, { timeout: 30_000 });
      await expect(listedRow).toContainText("order.refund");
      await expect(listedRow).toContainText(fixture.cashier.name);
      await listedRow.getByRole("button", { name: `Open entry ${row.id}` }).click();
      const drawer = page.getByRole("dialog", { name: `Audit entry ${row.id}` });
      await expect(drawer).toBeVisible({ timeout: 30_000 });
      const context = drawer.getByRole("table", { name: "Context" });
      await expect(rowOf(context, "reason")).toContainText(reason);
      await expect(rowOf(context, "amount")).toContainText("150.00");
      await expect(rowOf(context, "order_id")).toContainText(String(order.id));
      await expect(rowOf(context, "approved_by_user_id")).toContainText(fixture.manager.name);
      // the refund names the order: its record is one tap away
      await expect(drawer.getByRole("link", { name: "Open the order's record" })).toBeVisible();
    } finally {
      await retireRefundProducts(fixture);
    }
  });
});
