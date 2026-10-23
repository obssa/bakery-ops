import { expect, test } from "@playwright/test";
import { Api } from "../support/api";
import { requireAdmin } from "../support/env";
import { buildStationFixture, loginOnce } from "../support/fixture";
import { ledgerOf } from "../support/inventory";
import { openAs } from "../support/pad";
import { currentShift, ensureOpenShift, settleStation } from "../support/shifts";

// Phase 3 gate project p3-handover, test 1 (docs/phases/phase-3-plan.md section 7; the screens are step 3.7b): a closing count with one
// variance is explained, handed in and approved side by side, the adjustment lands in the ledger, the count locks and shows its hash,
// and the next shift's opening count is prefilled with the locked values and shows the handover panel.
//
// Test 2 (the till's cash declaration) is step 3.7d's and joins this file then.
//
// Built through the admin API in the isolated "E2E Test Branch", so it carries @live. The kitchen station already carries a shift that
// other fixtures opened, so the flow settles it first and opens a carried shift again at the end, as every other fixture expects.

interface CountView {
  id: number;
  type: string;
  status: string;
  location: { id: number };
  chain: { chain_seq: number; payload_hash: string; previous_hash: string | null } | null;
  lines: { id: number; item: { id: number }; expected_qty: string; counted_qty: string | null; variance_qty: string | null; reason: { code: string } | null }[];
}

const SCALE = 4;

function scaled(value: string): bigint {
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole + fraction.padEnd(SCALE, "0").slice(0, SCALE));
}

function plain(units: bigint): string {
  const negative = units < BigInt(0);
  const digits = (negative ? -units : units).toString().padStart(SCALE + 1, "0");
  return `${negative ? "-" : ""}${digits.slice(0, -SCALE)}.${digits.slice(-SCALE)}`;
}

/** "9500.0000" -> "9500", "0.2500" -> "0.25": how the screen shows a quantity. */
function shown(value: string): string {
  const text = value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
  return text === "-0" ? "0" : text;
}

async function ensureReason(admin: Api, code: string, names: { name_en: string; name_am: string }): Promise<void> {
  const reasons = (await admin.get<{ data: { code: string; is_active: boolean }[] }>("/variance-reasons")).data;
  if (reasons.some((r) => r.code === code && r.is_active)) return;
  await admin.post("/variance-reasons", { code, ...names });
}

test.describe("p3 handover", () => {
  test("a closing count with a variance is approved, locked and hashed, and the next opening is prefilled @live", async ({ browser }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const { admin, station, worker, location, ingredient } = fixture;
    await ensureReason(admin, "miscount", { name_en: "Miscount", name_am: "የቆጠራ ስህተት" });
    await settleStation(admin, station);
    await ensureOpenShift(admin, station);
    const running = await currentShift(admin, station);
    expect(running?.status).toBe("open");
    const shiftId = running!.id;

    const chefSession = await loginOnce(worker.phone, worker.password, fixture.branchId);
    const noteText = `Mixer is noisy ${ingredient.name_en.slice(-6)}`;
    const make = () => browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    const [chefContext, adminContext] = [await make(), await make()];
    let countId = 0;
    let hash = "";
    let variance = "";
    let countedFigure = "";
    let lineId = 0;
    try {
      // --- the chef closes the shift and counts: one line differs, it needs a reason ---------------------------------------------
      const chef = await chefContext.newPage();
      await openAs(chef, chefSession.api, "en/kds/kitchen/");
      const bar = chef.getByRole("region", { name: "Shift", exact: true });
      await expect(bar).toContainText("Shift open", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Close shift", exact: true }).click();
      await chef.getByRole("dialog", { name: "Close the shift" }).getByRole("button", { name: "Close shift", exact: true }).click();
      await expect(bar).toContainText("Closing count in progress", { timeout: 15_000 });
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(chef).toHaveURL(new RegExp(`/shifts/count/\\?shift=${shiftId}$`));
      await expect(chef.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });

      const counts = (await admin.get<{ data: CountView[] }>(`/shifts/${shiftId}/counts`)).data.filter((c) => c.status === "draft" && c.location.id === location.id);
      expect(counts).toHaveLength(1);
      countId = counts[0].id;
      const mine = counts[0].lines.find((l) => l.item.id === ingredient.id);
      expect(mine).toBeTruthy();
      lineId = mine!.id;
      const expected = mine!.expected_qty;
      const counted = plain(scaled(expected) - scaled("500"));
      countedFigure = counted;

      // "nothing changed" touches every line at its prefill (older runs left other ingredients here); then this one is corrected
      await chef.getByRole("button", { name: "Nothing changed", exact: true }).click();
      const figure = chef.getByLabel(`Counted: ${ingredient.name_en}`);
      await expect(figure).toHaveValue(shown(expected), { timeout: 15_000 });
      await figure.fill(shown(counted));
      await figure.press("Tab");
      const row = chef.getByRole("row").filter({ hasText: ingredient.name_en });
      await expect(row).toContainText("-500", { timeout: 15_000 });

      // submitting without a reason is refused on the screen: the review names the line and does not offer the submit
      await chef.getByRole("button", { name: "Review and submit", exact: true }).click();
      const review = chef.getByRole("dialog", { name: "Review the count" });
      await expect(review).toContainText(ingredient.name_en);
      await expect(review.getByRole("button", { name: "Submit count", exact: true })).toBeDisabled();
      await review.getByRole("button", { name: "Back to the count", exact: true }).click();

      await chef.getByLabel(`Reason: ${ingredient.name_en}`).selectOption({ label: "Miscount" });
      await expect(chef.getByLabel(`Reason: ${ingredient.name_en}`)).toHaveValue(/\d+/);

      // a handover note for the next shift
      await chef.getByLabel("Note for the next shift").fill(noteText);
      await chef.getByRole("button", { name: "Add note", exact: true }).click();
      await expect(chef.getByRole("region", { name: "Handover notes" })).toContainText(noteText, { timeout: 15_000 });

      await chef.getByRole("button", { name: "Review and submit", exact: true }).click();
      await expect(review).toContainText("-500");
      await review.getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(chef.getByText("Waiting for a manager to approve")).toBeVisible({ timeout: 30_000 });

      const handedIn = (await admin.get<CountView>(`/stock-counts/${countId}`));
      expect(handedIn.status).toBe("pending_approval");
      const line = handedIn.lines.find((l) => l.item.id === ingredient.id)!;
      variance = line.variance_qty!;
      expect(shown(variance)).toBe("-500");
      expect(line.reason?.code).toBe("miscount");

      // --- the admin sees it in the queue and approves it side by side ---------------------------------------------------------
      const boss = await adminContext.newPage();
      await openAs(boss, admin, "en/admin/shifts/");
      const queue = boss.getByRole("region", { name: "Counts waiting for a decision" });
      await expect(queue).toBeVisible({ timeout: 30_000 });
      const entry = queue.getByRole("listitem").filter({ hasText: `Count #${countId}` });
      await expect(entry).toContainText(ingredient.name_en, { timeout: 30_000 });
      await entry.getByRole("link", { name: `Review count #${countId}` }).click();

      await expect(boss.getByRole("heading", { name: `Count #${countId}`, level: 1 })).toBeVisible({ timeout: 30_000 });
      const side = boss.getByRole("row").filter({ hasText: ingredient.name_en });
      await expect(side).toContainText(shown(expected));
      await expect(side).toContainText(shown(counted));
      await expect(side).toContainText("-500");
      await expect(side).toContainText("Miscount");
      await boss.getByRole("button", { name: "Approve", exact: true }).click();
      await expect(boss.getByText("Locked", { exact: true }).first()).toBeVisible({ timeout: 30_000 });

      const locked = await admin.get<CountView>(`/stock-counts/${countId}`);
      expect(locked.status).toBe("locked");
      hash = locked.chain!.payload_hash;
      expect(hash).toMatch(/^[0-9a-f]{64}$/);
      await expect(boss.getByText(hash)).toBeVisible();

      // the correcting movement is in the ledger, and the chain of the place verifies
      const adjustments = (await ledgerOf(admin, ingredient.id, location.id)).filter((m) => m.reason === "count_adjustment");
      expect(adjustments).toHaveLength(1);
      expect(adjustments[0].ref_type).toBe("stock_count_line");
      expect(adjustments[0].ref_id).toBe(lineId);
      expect(scaled(adjustments[0].qty)).toBe(scaled(variance));
      const verified = await admin.get<{ ok: boolean; head_hash: string | null }>(`/locations/${location.id}/chain/verify`);
      expect(verified.ok).toBe(true);
      expect(verified.head_hash).toBe(hash);

      // --- the next shift: its opening count is prefilled with the locked values, and the handover panel carries the note ------------
      expect(await currentShift(admin, station)).toBeNull();
      await chef.reload();
      await expect(bar).toContainText("No shift is running", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Start shift", exact: true }).click();
      await expect(bar).toContainText("Opening count in progress", { timeout: 15_000 });
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(chef.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });

      const handover = chef.getByRole("region", { name: "Handover from the last shift" });
      await expect(handover).toContainText(noteText, { timeout: 30_000 });
      await expect(handover).toContainText(hash.slice(0, 8));

      const opened = chef.getByRole("row").filter({ hasText: ingredient.name_en });
      await expect(opened).toContainText(shown(counted), { timeout: 15_000 });
      const prefilled = chef.getByLabel(`Counted: ${ingredient.name_en}`);
      await chef.getByRole("button", { name: "Nothing changed", exact: true }).click();
      await expect(prefilled).toHaveValue(shown(counted), { timeout: 15_000 });
      await chef.getByRole("button", { name: "Review and submit", exact: true }).click();
      await chef.getByRole("dialog", { name: "Review the count" }).getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(chef.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });
    } finally {
      await chefContext.close();
      await adminContext.close();
    }

    // the opening count carried the locked figure and the shift is open
    const next = await currentShift(admin, station);
    expect(next?.status).toBe("open");
    expect(next?.id).not.toBe(shiftId);
    const opening = (await admin.get<{ data: CountView[] }>(`/shifts/${next!.id}/counts`)).data.find((c) => c.location.id === location.id && c.type === "opening");
    const carried = opening?.lines.find((l) => l.item.id === ingredient.id);
    expect(carried).toBeTruthy();
    expect(scaled(carried!.counted_qty!)).toBe(scaled(countedFigure));
    await ensureOpenShift(admin, station);
  });
});
