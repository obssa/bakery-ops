import { expect, test } from "@playwright/test";
import { Api, uuid } from "../support/api";
import { requireAdmin } from "../support/env";
import { buildStationFixture, buildTillStaff, findOrCreateStation, loginOnce, sendOrder } from "../support/fixture";
import { ledgerOf } from "../support/inventory";
import { openAs } from "../support/pad";
import { currentShift, ensureDrawer, ensureOpenShift, settleStation, tallyFor, tallyTotalOf } from "../support/shifts";

// Phase 3 gate project p3-handover, test 1 (docs/phases/phase-3-plan.md section 7; the screens are step 3.7b): a closing count with one
// variance is explained, handed in and approved side by side, the adjustment lands in the ledger, the count locks and shows its hash,
// and the next shift's opening count is prefilled with the locked values and shows the handover panel.
//
// Test 2 (step 3.7d, the till's cash screens): the cashier declares the drawer before the shift opens, a cash payment shows in the till's
// widget, the closing count is short by 20.00 with the cash slips 10.00 short, a reason is chosen, the admin sees the cash side by side
// and approves, and the next opening shows the float the closing left, which the cashier declares.
//
// Built through the admin API in the isolated "E2E Test Branch", so it carries @live. The kitchen station already carries a shift that
// other fixtures opened, so the flow settles it first and opens a carried shift again at the end, as every other fixture expects.

interface CountView {
  id: number;
  type: string;
  status: string;
  location: { id: number };
  cash?: {
    counted_total: string;
    expected_total: string;
    variance_total: string;
    cash_sales_total: string | null;
    carried_float_total: string | null;
    float_left_total: string | null;
    denominations: Record<string, number>;
    reason: { code: string } | null;
    channels: { code: string; expected_receipts: number; expected_sum: string; declared_receipts: number | null; declared_sum: string | null; difference_sum: string | null }[];
  } | null;
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
      const entry = queue.getByRole("listitem").filter({ hasText: new RegExp(`Count #${countId}(?!\\d)`) });
      await expect(entry).toContainText(ingredient.name_en, { timeout: 30_000 });
      await entry.getByRole("link", { name: `Review count #${countId}`, exact: true }).click();

      await expect(boss.getByRole("heading", { name: `Count #${countId}`, level: 1, exact: true })).toBeVisible({ timeout: 30_000 });
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
      await chef.goto("en/kds/kitchen/");
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

  test("the till's cash declaration by denomination with a variance and the channel reconciliation @live", async ({ browser }) => {
    test.setTimeout(300_000);
    const credentials = requireAdmin(test.skip);
    // a kitchen product to sell, and the fixed cashier at the till of the E2E branch, which gets a cash drawer
    const fixture = await buildStationFixture(credentials, "kitchen", { price: "60.00" });
    const { admin } = fixture;
    const staff = await buildTillStaff(credentials);
    const { cashier } = staff;
    const till = await findOrCreateStation(admin, "till");
    const drawer = await ensureDrawer(admin, till);
    await ensureReason(admin, "miscount", { name_en: "Miscount", name_am: "የቆጠራ ስህተት" });
    await settleStation(admin, till);
    expect(await currentShift(admin, till)).toBeNull();

    const make = () => browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    const [cashierContext, adminContext] = [await make(), await make()];
    let closingId = 0;
    let hash = "";
    let openingTotal = "";
    try {
      // --- the cashier starts the shift and declares the drawer before it opens ----------------------------------------------------
      const page = await cashierContext.newPage();
      await openAs(page, cashier.api, "en/till/");
      const bar = page.getByRole("region", { name: "Shift", exact: true });
      await expect(bar).toContainText("No shift is running", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Start shift", exact: true }).click();
      await expect(bar).toContainText("Opening count in progress", { timeout: 15_000 });

      const shiftId = (await currentShift(admin, till))!.id;
      const opening = (await admin.get<{ data: CountView[] }>(`/shifts/${shiftId}/counts`)).data.find((c) => c.location.id === drawer.id && c.type === "opening")!;
      expect(opening.cash).toBeTruthy();
      const keys = Object.keys(opening.cash!.denominations);
      // what the last closing left is declared as it stands, written by denomination (settling the till left 0.00 the first time: an
      // empty drawer, declared with "Confirm this count")
      const expectedOpening = opening.cash!.expected_total;
      const openingTally = tallyFor(expectedOpening, keys);
      openingTotal = tallyTotalOf(openingTally);
      const used = Object.keys(openingTally).filter((k) => openingTally[k] > 0);

      await bar.getByRole("button", { name: "Declare the drawer", exact: true }).click();
      const declare = page.getByRole("dialog", { name: "Declare the drawer" });
      for (const key of used) {
        const field = declare.getByLabel(`Count of ${key}`, { exact: true });
        await field.fill(String(openingTally[key]));
        await field.press("Tab");
      }
      if (used.length === 0) await declare.getByRole("button", { name: "Confirm this count", exact: true }).click();
      await expect(declare.getByText("Declared", { exact: true })).toBeVisible({ timeout: 20_000 });
      await declare.getByRole("button", { name: "Done", exact: true }).click();

      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(page.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });
      if (used.length > 0) await expect(page.getByLabel(`Count of ${used[0]}`, { exact: true })).toHaveValue(String(openingTally[used[0]]), { timeout: 20_000 });
      else await expect(page.getByText("Declared", { exact: true })).toBeVisible({ timeout: 20_000 });
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      const openReview = page.getByRole("dialog", { name: "Review the cash count" });
      await expect(openReview).toContainText(openingTotal);
      await expect(openReview.getByRole("button", { name: "Submit count", exact: true })).toBeEnabled({ timeout: 15_000 });
      await openReview.getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(page.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });

      // --- a cash payment is taken, and the till's widget shows it ---------------------------------------------------------------
      await page.goto("en/till/");
      await expect(bar).toContainText("Shift open", { timeout: 30_000 });
      const widget = page.getByRole("region", { name: "Cash in the drawer" });
      await expect(widget).toContainText(openingTotal, { timeout: 30_000 });
      const order = await sendOrder(fixture);
      await cashier.api.post(`/orders/${order.id}/payments`, { client_uuid: uuid(), channel_code: "cash", amount: "60.00", tendered_amount: "100.00" });
      const channels = widget.getByRole("list", { name: "Taken by channel" });
      await expect(channels).toContainText("Cash", { timeout: 30_000 });
      await expect(channels).toContainText("60.00");
      const sold = await admin.get<{ cash_sales_total: string; expected_total: string }>(`/shifts/${shiftId}/cash`);
      expect(sold.cash_sales_total).toBe("60.00");

      // --- closing: the drawer is short by 20.00 and the cash slips are 10.00 short ----------------------------------------------
      const expectedClosing = sold.expected_total;
      const target = tallyFor(minus(expectedClosing, "20.00"), keys);
      await bar.getByRole("button", { name: "Close shift", exact: true }).click();
      await page.getByRole("dialog", { name: "Close the shift" }).getByRole("button", { name: "Close shift", exact: true }).click();
      await expect(bar).toContainText("Closing count in progress", { timeout: 15_000 });
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(page.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });

      const closing = (await admin.get<{ data: CountView[] }>(`/shifts/${shiftId}/counts`)).data.find((c) => c.location.id === drawer.id && c.type === "closing" && c.status === "draft")!;
      closingId = closing.id;
      for (const key of Object.keys(target).filter((k) => target[k] > 0)) {
        const field = page.getByLabel(`Count of ${key}`, { exact: true });
        await field.fill(String(target[key]));
        await field.press("Tab");
      }
      const countedTotal = tallyTotalOf(target);
      await expect(page.getByRole("region", { name: "Cash figures" })).toContainText("-20.00", { timeout: 20_000 });
      const float = page.getByLabel("Float left in the drawer", { exact: true });
      await float.fill("10.00");
      await float.press("Tab");
      const receipts = page.getByLabel("Receipts counted: Cash", { exact: true });
      await receipts.fill("1");
      await receipts.press("Tab");
      const sum = page.getByLabel("Sum counted: Cash", { exact: true });
      await sum.fill("50.00");
      await sum.press("Tab");
      await expect(page.getByRole("region", { name: "Payment channels" })).toContainText("-10.00", { timeout: 20_000 });

      // handing in without a reason is refused on the screen
      await expect(page.getByRole("region", { name: "Why does it differ?" })).toBeVisible({ timeout: 20_000 });
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      const review = page.getByRole("dialog", { name: "Review the cash count" });
      await expect(review).toContainText("choose a reason");
      await expect(review.getByRole("button", { name: "Submit count", exact: true })).toBeDisabled();
      await review.getByRole("button", { name: "Back to the count", exact: true }).click();

      await page.getByLabel("Reason", { exact: true }).selectOption({ label: "Miscount" });
      await expect(page.getByLabel("Reason", { exact: true })).toHaveValue(/\d+/);
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      await expect(review).toContainText("-20.00");
      await expect(review).toContainText("-10.00");
      await expect(review.getByRole("button", { name: "Submit count", exact: true })).toBeEnabled({ timeout: 15_000 });
      await review.getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(page.getByText("Waiting for a manager to approve")).toBeVisible({ timeout: 30_000 });

      const handedIn = await admin.get<CountView>(`/stock-counts/${closingId}`);
      expect(handedIn.status).toBe("pending_approval");
      expect(handedIn.cash).toMatchObject({
        counted_total: countedTotal,
        expected_total: expectedClosing,
        variance_total: "-20.00",
        cash_sales_total: "60.00",
        float_left_total: "10.00",
      });
      expect(handedIn.cash!.reason?.code).toBe("miscount");
      expect(handedIn.cash!.channels.find((c) => c.code === "cash")).toMatchObject({ expected_receipts: 1, expected_sum: "60.00", declared_receipts: 1, declared_sum: "50.00", difference_sum: "-10.00" });

      // --- the admin sees the cash side by side and approves --------------------------------------------------------------------
      const boss = await adminContext.newPage();
      await openAs(boss, admin, "en/admin/shifts/");
      const queue = boss.getByRole("region", { name: "Counts waiting for a decision" });
      await expect(queue).toBeVisible({ timeout: 30_000 });
      const entry = queue.getByRole("listitem").filter({ hasText: new RegExp(`Count #${closingId}(?!\\d)`) });
      await expect(entry).toBeVisible({ timeout: 30_000 });
      await entry.getByRole("link", { name: `Review count #${closingId}`, exact: true }).click();
      await expect(boss.getByRole("heading", { name: `Count #${closingId}`, level: 1, exact: true })).toBeVisible({ timeout: 30_000 });
      const side = boss.getByRole("region", { name: "Cash, side by side" });
      await expect(side).toContainText(countedTotal);
      await expect(side).toContainText(expectedClosing);
      await expect(side).toContainText("-20.00");
      await expect(side).toContainText("-10.00");
      await expect(side).toContainText("Miscount");
      await boss.getByRole("button", { name: "Approve", exact: true }).click();
      await expect(boss.getByText("Locked", { exact: true }).first()).toBeVisible({ timeout: 30_000 });

      const locked = await admin.get<CountView>(`/stock-counts/${closingId}`);
      expect(locked.status).toBe("locked");
      hash = locked.chain!.payload_hash;
      expect(hash).toMatch(/^[0-9a-f]{64}$/);
      await expect(boss.getByText(hash)).toBeVisible();
      const verified = await admin.get<{ ok: boolean; head_hash: string | null }>(`/locations/${drawer.id}/chain/verify`);
      expect(verified.ok).toBe(true);
      expect(verified.head_hash).toBe(hash);

      // --- the next opening shows the float the last closing left, and the cashier declares it -----------------------------------------
      expect(await currentShift(admin, till)).toBeNull();
      await page.goto("en/till/");
      await expect(bar).toContainText("No shift is running", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Show handover", exact: true }).click();
      await expect(page.getByRole("region", { name: "Handover from the last shift" })).toContainText("Float left in the drawer: 10.00", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Start shift", exact: true }).click();
      await expect(bar).toContainText("Opening count in progress", { timeout: 15_000 });
      await bar.getByRole("button", { name: "Declare the drawer", exact: true }).click();
      const again = page.getByRole("dialog", { name: "Declare the drawer" });
      await expect(again).toContainText("Expected is the float the last closing left: 10.00", { timeout: 20_000 });
      const ten = again.getByLabel("Count of 10", { exact: true });
      await ten.fill("1");
      await ten.press("Tab");
      await expect(again.getByText("Declared", { exact: true })).toBeVisible({ timeout: 20_000 });
      await again.getByRole("button", { name: "Done", exact: true }).click();
      await bar.getByRole("link", { name: "Open the count" }).click();
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      const lastSubmit = page.getByRole("dialog", { name: "Review the cash count" }).getByRole("button", { name: "Submit count", exact: true });
      await expect(lastSubmit).toBeEnabled({ timeout: 15_000 });
      await lastSubmit.click();
      await expect(page.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });
    } finally {
      await cashierContext.close();
      await adminContext.close();
    }

    // the new shift opened with the carried float counted exactly
    const next = await currentShift(admin, till);
    expect(next?.status).toBe("open");
    const reopened = (await admin.get<{ data: CountView[] }>(`/shifts/${next!.id}/counts`)).data.find((c) => c.location.id === drawer.id && c.type === "opening");
    expect(reopened?.cash).toMatchObject({ carried_float_total: "10.00", counted_total: "10.00", variance_total: "0.00" });
    expect(openingTotal).not.toBe("");
    await ensureOpenShift(admin, till);
  });
});

/** a minus b for two money strings (2 dp), exact. */
function minus(a: string, b: string): string {
  const cents = (v: string) => {
    const [w, f = ""] = v.split(".");
    return BigInt(w) * BigInt(100) + BigInt(f.padEnd(2, "0").slice(0, 2));
  };
  const diff = cents(a) - cents(b);
  const digits = (diff < BigInt(0) ? -diff : diff).toString().padStart(3, "0");
  return `${diff < BigInt(0) ? "-" : ""}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}
