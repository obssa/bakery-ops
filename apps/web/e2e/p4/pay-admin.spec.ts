import { expect, test, type Locator } from "@playwright/test";
import { ApiFailure } from "../support/api";
import { live, requireAdmin, run } from "../support/env";
import { businessToday, loginOnce, orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import {
  buildPayFixture,
  ensureRunChannels,
  payBatch,
  removeChannel,
  serveAll,
  takeawayOrder,
  tidyOrder,
  uploadReceipt,
  type Channel,
} from "../support/payments";
import { canvasJpeg, runTint } from "../support/photo";
import { inboxOf, waitForQueue } from "../support/production";

// Phase 4 project p4-pay-photo-split (the name matches /pay/), step 4.5c: the admin payments screens.
//
// 1. Payment methods: the admin adds a run-unique method with a reference pattern checked as it is typed, edits its rules (a photo, the
//    hints), moves it up past the fixture's bank transfer and back down (PUT /payment-channels/order with every live id once), switches
//    it off and removes it. The cash row shows no edit, switch or remove control. The default channels are only ever renumbered in place
//    by the API (their order is kept); a test never edits one.
// 2. Payments: the cashier pays a served order through the API, 180.00 on the run's wallet (a receipt photo and a reference) and 70.00 on
//    the run's bank transfer (a reference only). The admin finds the wallet payment in the list filtered by its method, opens its photo (a
//    fresh GET /files/{file}/url, the picture loads), disputes it (a reason too short is refused before anything is sent) and verifies it.
//    The reconciliation of today counts both methods exactly (they are run-unique, so the shared branch's other payments never reach their
//    rows); the bank's "without photo" cell drills into the list filtered to that day, that method and missing_photo=1. The deep link of
//    the payment_disputed notification (/admin/payments?payment={id}) opens the payment; in CI, where the queue worker runs at once, it
//    is also followed from the manager's inbox (on the live host the cron would make the flow wait minutes for it).
//
// Fixtures through the admin API of the isolated "E2E Test Branch" (e2e/support/payments.ts), run-unique names; so the flows carry @live.

let made = 0;

/** A row of the table by its row header, the run-unique name of a payment method (the inner locator is the page's: it is matched inside each row). */
function rowOf(table: Locator, name: string): Locator {
  return table.getByRole("row").filter({ has: table.page().getByRole("rowheader", { name, exact: true }) });
}

async function channelByCode(admin: { get<T>(path: string, query?: Record<string, string | number>): Promise<T> }, code: string): Promise<Channel & { sort: number; reference_hint_en: string | null; photo_hint_am: string | null }> {
  const all = (await admin.get<{ data: (Channel & { sort: number; reference_hint_en: string | null; photo_hint_am: string | null })[] }>("/payment-channels", { all: 1 })).data;
  const found = all.find((c) => c.code === code);
  if (!found) throw new Error(`no channel ${code}`);
  return found;
}

test.describe("p4 admin payments", () => {
  test("the admin adds a payment method, edits its rules, moves it, switches it off and removes it; cash is locked @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    // the fixture's two methods sit right above the one this flow adds, so its moves only swap run-unique rows
    const fixtureChannels = await ensureRunChannels(admin);
    const code = `e2e_admin_${run}_${++made}`;
    const name = `E2E admin method ${run} ${made}`;
    let created: Channel | null = null;
    try {
      await openAs(page, admin, "en/admin/payments/channels/");
      await expect(page.getByRole("heading", { level: 1, name: "Payment methods" })).toBeVisible({ timeout: 30_000 });
      const list = page.getByRole("table", { name: "Payment methods" });
      await expect(rowOf(list, fixtureChannels.bank.name_en)).toBeVisible({ timeout: 30_000 });

      // cash is locked: no edit, switch or remove control
      const cash = list.getByRole("row").filter({ hasText: "Locked" });
      await expect(cash).toHaveCount(1);
      await expect(cash.getByRole("button", { name: /^(Edit|Switch off|Switch on|Remove) / })).toHaveCount(0);

      // add: the pattern is checked as it is typed, and a sample reference is tried against it
      const form = page.getByRole("region", { name: "Add a payment method" });
      await form.getByLabel("Code").fill(code);
      await form.getByLabel("Name (English)").fill(name);
      await form.getByLabel("Name (Amharic)").fill(`የሙከራ የክፍያ መንገድ ${run} ${made}`);
      await form.getByLabel("Ask for a reference number").check();
      const pattern = form.getByLabel("Reference pattern");
      await pattern.fill("FT[0-9");
      await expect(form.getByRole("status", { name: "Pattern check" })).toContainText("cannot read");
      await pattern.fill("FT[0-9]{6}");
      await expect(form.getByRole("status", { name: "Pattern check" })).toContainText("reads well");
      const sample = form.getByLabel("Try a reference");
      await sample.fill("FT123456");
      await expect(form.getByRole("status", { name: "Sample check" })).toHaveText("Matches the pattern.");
      await sample.fill("FT12");
      await expect(form.getByRole("status", { name: "Sample check" })).toHaveText("Does not match the pattern.");
      await form.getByLabel("Reference hint (English)").fill("FT and six digits");
      await form.getByLabel("Reference hint (Amharic)").fill("FT እና ስድስት አሃዞች");
      const post = page.waitForResponse((r) => r.url().endsWith("/api/v1/payment-channels") && r.request().method() === "POST");
      await form.getByRole("button", { name: "Add payment method" }).click();
      expect((await post).status()).toBe(201);
      await expect(form.getByRole("status")).toContainText(`Saved: ${name}`);
      created = await channelByCode(admin, code);
      expect(created).toMatchObject({ is_enabled: true, requires_photo: false, requires_reference: true, reference_pattern: "FT[0-9]{6}", reference_hint_en: "FT and six digits" });
      const mine = rowOf(list, name);
      await expect(mine).toContainText("Reference");
      await expect(mine).toContainText("FT[0-9]{6}");

      // edit its rules: a photo too, with its hints; the code stays
      await mine.getByRole("button", { name: `Edit ${name}` }).click();
      const edit = page.getByRole("region", { name: `Edit ${name}` });
      await expect(edit.getByLabel("Code")).toBeDisabled();
      await edit.getByLabel("Ask for a photo of the receipt").check();
      await edit.getByLabel("Photo hint (English)").fill("The confirmation screen");
      await edit.getByLabel("Photo hint (Amharic)").fill("የማረጋገጫው ገጽ");
      const patch = page.waitForResponse((r) => r.url().endsWith(`/api/v1/payment-channels/${created?.id}`) && r.request().method() === "PATCH");
      await edit.getByRole("button", { name: "Save changes" }).click();
      const patched = await patch;
      expect(patched.status()).toBe(200);
      expect(patched.request().postDataJSON()).toEqual({ requires_photo: true, photo_hint_en: "The confirmation screen", photo_hint_am: "የማረጋገጫው ገጽ" });
      await expect(mine).toContainText("Photo");
      expect(await channelByCode(admin, code)).toMatchObject({ requires_photo: true, requires_reference: true, photo_hint_am: "የማረጋገጫው ገጽ" });

      // move it up past the bank transfer, then back down: every live id once, in the new order
      const order = () => page.waitForResponse((r) => r.url().endsWith("/api/v1/payment-channels/order") && r.request().method() === "PUT");
      const names = async () => (await list.getByRole("rowheader").allTextContents()).map((text) => text.trim());
      const before = await names();
      expect(before.indexOf(name)).toBe(before.indexOf(fixtureChannels.bank.name_en) + 1);
      let put = order();
      await mine.getByRole("button", { name: `Move up ${name}` }).click();
      let answer = await put;
      expect(answer.status()).toBe(200);
      const liveChannels = (await admin.get<{ data: Channel[] }>("/payment-channels")).data;
      const sent = (answer.request().postDataJSON() as { ids: number[] }).ids;
      expect([...sent].sort((a, b) => a - b)).toEqual(liveChannels.map((c) => c.id).sort((a, b) => a - b));
      expect(sent.indexOf(created.id)).toBe(sent.indexOf(fixtureChannels.bank.id) - 1);
      await expect.poll(async () => (await names()).indexOf(name)).toBe(before.indexOf(name) - 1);
      put = order();
      await mine.getByRole("button", { name: `Move down ${name}` }).click();
      answer = await put;
      expect(answer.status()).toBe(200);
      await expect.poll(async () => (await names()).indexOf(name)).toBe(before.indexOf(name));
      const afterIds = (await admin.get<{ data: Channel[] }>("/payment-channels")).data.map((c) => c.id);
      expect(afterIds.indexOf(created.id)).toBe(afterIds.indexOf(fixtureChannels.bank.id) + 1);

      // switch it off: the till no longer offers it, the list says Off
      await mine.getByRole("button", { name: `Switch off ${name}` }).click();
      await expect(mine.getByText("Off", { exact: true })).toBeVisible();
      await expect.poll(async () => (await channelByCode(admin, code)).is_enabled).toBe(false);
      await expect(mine.getByRole("button", { name: `Switch on ${name}` })).toBeVisible();

      // remove it: asked once more, then it moves to the removed ones, with no control left
      await mine.getByRole("button", { name: `Remove ${name}` }).click();
      await mine.getByRole("button", { name: `Confirm: remove ${name}` }).click();
      await expect(mine.getByText("Removed", { exact: true })).toBeVisible();
      await expect(mine.getByRole("button")).toHaveCount(0);
      expect((await channelByCode(admin, code)).deleted_at).not.toBeNull();
      const after = await names();
      expect(after.indexOf(name)).toBeGreaterThan(after.indexOf(fixtureChannels.bank.name_en));
    } finally {
      if (created) await removeChannel(admin, created);
      await removeChannel(admin, fixtureChannels.wallet);
      await removeChannel(admin, fixtureChannels.bank);
    }
  });

  test("a wallet payment with its photo is found, its photo opens from a fresh URL, it is disputed then verified, the reconciliation counts it, and its link opens it @live", async ({ page, browser }, testInfo) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    const { wallet, bank } = fixture.channels;
    let orderId: number | null = null;
    try {
      // the cashier pays a served order of 250.00 through the API: the wallet with a photo and a reference, the bank with a reference
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      await serveAll(fixture, sent.id);
      await page.goto("en/login/");
      const photo = await uploadReceipt(fixture.cashier.api, await canvasJpeg(page, 1200, 900, 0.9, runTint(run)));
      const digits = String(parseInt(run.slice(-6), 36) % 1_000_000).padStart(6, "0");
      const [walletPayment, bankPayment] = await payBatch(fixture, sent.id, [
        { channel_code: wallet.code, amount: "180.00", reference_no: `TX${digits}`, receipt_file_id: photo.id },
        { channel_code: bank.code, amount: "70.00", reference_no: `E2E-${run}` },
      ]);
      expect(walletPayment.receipt?.file_id).toBe(photo.id);
      expect(bankPayment.receipt).toBeNull();
      const label = orderLabel(sent);

      // the list, filtered by the run's wallet
      await openAs(page, fixture.admin, "en/admin/payments/");
      await expect(page.getByRole("heading", { level: 1, name: "Payments" })).toBeVisible({ timeout: 30_000 });
      const filters = page.getByRole("search", { name: "Filters" });
      await filters.getByLabel("Payment method").selectOption({ label: wallet.name_en });
      await filters.getByRole("button", { name: "Apply filters" }).click();
      await expect(page).toHaveURL(new RegExp(`channel_id=${wallet.id}`));
      const table = page.getByRole("table", { name: "Payments, newest first" });
      const row = table.getByRole("row").filter({ has: page.getByRole("button", { name: `Open payment ${walletPayment.id}`, exact: true }) });
      await expect(row).toBeVisible({ timeout: 30_000 });
      await expect(table.getByRole("button", { name: /^Open payment / })).toHaveCount(1);
      await expect(row).toContainText("180.00");
      await expect(row).toContainText(`TX${digits}`);
      await expect(row).toContainText(label);
      await expect(row).toContainText("Recorded");

      // the photo: the viewer asks for a fresh signed URL, and the picture loads
      const signed = page.waitForResponse((r) => r.url().includes(`/api/v1/files/${photo.id}/url`) && r.request().method() === "GET");
      await row.getByRole("button", { name: `Receipt photo of payment ${walletPayment.id}` }).click();
      expect((await signed).status()).toBe(200);
      const viewer = page.getByRole("dialog", { name: `Receipt photo of payment ${walletPayment.id}` });
      const picture = viewer.getByRole("img", { name: `Receipt photo of payment ${walletPayment.id}` });
      await expect(picture).toBeVisible();
      await expect.poll(() => picture.evaluate((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth)).toBe(1200);
      await viewer.getByRole("button", { name: "Close" }).click();
      await expect(viewer).toHaveCount(0);

      // dispute it: a reason that is too short is refused before anything is sent
      await row.getByRole("button", { name: `Open payment ${walletPayment.id}`, exact: true }).click();
      await expect(page).toHaveURL(new RegExp(`payment=${walletPayment.id}`));
      const detail = page.getByRole("dialog", { name: `Payment ${walletPayment.id}` });
      await expect(detail).toContainText(wallet.name_en);
      await expect(detail).toContainText("Recorded");
      const reason = detail.getByLabel("Why is it disputed?");
      await reason.fill("no");
      await detail.getByRole("button", { name: "Dispute payment" }).click();
      await expect(detail).toContainText("Say why the payment is disputed, in 3 to 255 characters.");
      await reason.fill(`E2E ${run}: not on the bank statement yet`);
      const dispute = page.waitForResponse((r) => r.url().endsWith(`/api/v1/payments/${walletPayment.id}/dispute`) && r.request().method() === "POST");
      await detail.getByRole("button", { name: "Dispute payment" }).click();
      const disputed = await dispute;
      expect(disputed.status()).toBe(200);
      expect(disputed.request().headers()["idempotency-key"]).toBeTruthy();
      await expect(detail.getByRole("status").filter({ hasText: "Payment disputed." })).toBeVisible();
      await expect(detail.getByText("Disputed", { exact: true }).first()).toBeVisible();
      await expect(detail).toContainText(`E2E ${run}: not on the bank statement yet`);
      await expect(detail.getByRole("button", { name: "Dispute payment" })).toHaveCount(0);
      await expect(row).toContainText("Disputed");

      // then verify it
      await detail.getByLabel("Note (optional)").fill("Found on the statement");
      const verify = page.waitForResponse((r) => r.url().endsWith(`/api/v1/payments/${walletPayment.id}/verify`) && r.request().method() === "POST");
      await detail.getByRole("button", { name: "Verify payment" }).click();
      expect((await verify).status()).toBe(200);
      await expect(detail.getByRole("status").filter({ hasText: "Payment verified." })).toBeVisible();
      await expect(detail.getByRole("button", { name: "Verify payment" })).toHaveCount(0);
      await detail.getByRole("button", { name: "Close" }).click();
      await expect(detail).toHaveCount(0);
      await expect(row).toContainText("Verified");
      expect((await fixture.admin.get<{ status: string; verified_at: string | null }>(`/payments/${walletPayment.id}`)).status).toBe("verified");

      // the reconciliation of today counts both run-unique methods exactly
      await page.getByRole("navigation", { name: "Payment sections" }).getByRole("link", { name: "Reconciliation" }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Reconciliation" })).toBeVisible();
      const today = businessToday();
      const day = page.getByRole("table", { name: `Day ${today}` });
      // receipts, amount, with photo, without photo, with reference, verified, disputed, voided, refunds, refunded, net
      await expect(rowOf(day, wallet.name_en).getByRole("cell")).toHaveText(["1", "180.00", "1", "0", "1", "1", "0", "0", "0", "0.00", "180.00"], { timeout: 30_000 });
      await expect(rowOf(day, bank.name_en).getByRole("cell")).toHaveText(["1", "70.00", "0", "1", "1", "0", "0", "0", "0", "0.00", "70.00"]);

      // the bank's missing photo drills into the list of that day, that method, missing_photo=1
      await rowOf(day, bank.name_en).getByRole("link", { name: `Show the payments without a photo: ${bank.name_en}, ${today}` }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Payments" })).toBeVisible();
      await expect(page).toHaveURL(/missing_photo=1/);
      await expect(page).toHaveURL(new RegExp(`channel_id=${bank.id}`));
      await expect(page.getByRole("search", { name: "Filters" }).getByLabel("Missing photo only")).toBeChecked();
      await expect(table.getByRole("button", { name: `Open payment ${bankPayment.id}`, exact: true })).toBeVisible({ timeout: 30_000 });
      await expect(table.getByRole("button", { name: /^Open payment / })).toHaveCount(1);

      // the deep link of the payment_disputed notification opens the payment
      await page.goto(`en/admin/payments/?payment=${walletPayment.id}`);
      const linked = page.getByRole("dialog", { name: `Payment ${walletPayment.id}` });
      await expect(linked).toBeVisible({ timeout: 30_000 });
      await expect(linked).toContainText("Verified");
      await expect(linked).toContainText("180.00");

      if (!live) {
        // in CI the queue runs at once: the manager's inbox holds the line, and following it opens the same payment
        const { api: managerApi } = await loginOnce(fixture.manager.phone, fixture.manager.password, fixture.branchId);
        await waitForQueue("the manager's payment_disputed line", async () =>
          (await inboxOf(managerApi)).find((line) => line.type === "payment_disputed" && line.deep_link === `/admin/payments?payment=${walletPayment.id}`) ?? null,
        );
        const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
        try {
          const inbox = await context.newPage();
          await openAs(inbox, managerApi, "en/admin/");
          await inbox.getByRole("button", { name: /^Notifications/ }).click();
          await inbox.getByRole("region", { name: "Notifications" }).getByRole("link", { name: new RegExp(`Payment disputed: order #${sent.order_no}.*${run}`) }).click();
          await expect(inbox.getByRole("dialog", { name: `Payment ${walletPayment.id}` })).toBeVisible({ timeout: 30_000 });
        } finally {
          await context.close();
        }
      }
    } catch (error) {
      if (error instanceof ApiFailure) console.error(error.message);
      throw error;
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, wallet);
      await removeChannel(fixture.admin, bank);
    }
  });
});
