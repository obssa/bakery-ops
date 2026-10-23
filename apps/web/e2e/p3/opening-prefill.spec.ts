import { expect, test } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { loginOnce } from "../support/fixture";
import { buildNightFixture, scheduleShift } from "../support/nightshift";
import { openAs } from "../support/pad";
import { currentShift, ensureOpenShift } from "../support/shifts";
import { addDays, addisDate } from "../../src/lib/shiftsAdmin";

// Phase 3 gate project p3-opening-prefill (docs/phases/phase-3-plan.md section 7; the screens are step 3.7c): a Night shift scheduled for
// yesterday's local date is started late by the admin ("Start now" on the roster calendar), the bread baker counts it open, closes it and
// the count locks; the next shift opens prefilled from that closing. The shift's date is its local START date, so the Night shift is
// yesterday's although it was opened and worked today, and the prefill crosses the midnight boundary.
//
// A Playwright test cannot make the server clock say 00:01, so the instant-level boundaries (23:59, 00:01, 05:59, 06:00) stay in Pest
// (CrossMidnightGateTest). This flow proves the date semantics end to end on any clock, including the live site, so it carries @live.
// Built through the admin API in the isolated "E2E Test Branch". The next shift is started from the baker's bar: when the clock happens
// to be inside the Night window it is a Night shift of that window's start date, otherwise the day's ad-hoc shift dated today, and the
// test reads the rule from the shift (origin, starts_at, opened_at) instead of assuming which one the clock gives.

interface CountView {
  id: number;
  type: string;
  status: string;
  location: { id: number };
  chain: { chain_seq: number; payload_hash: string; previous_hash: string | null } | null;
  lines: { item: { id: number }; carried_qty: string | null; counted_qty: string | null; expected_qty: string }[];
}

interface ShiftView {
  id: number;
  status: string;
  date: string;
  origin: string;
  opened_at: string | null;
  starts_at: string | null;
  template: { name_en: string } | null;
}

const SCALE = 4;

function scaled(value: string): bigint {
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole + fraction.padEnd(SCALE, "0").slice(0, SCALE));
}

test.describe("p3 opening prefill", () => {
  test("a Night shift scheduled for yesterday is started late, closed, and the next shift opens prefilled; dates are the local start dates @live", async ({ browser }) => {
    test.setTimeout(300_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildNightFixture(credentials);
    const { admin, station, location, baker, template, ingredient } = fixture;
    const today = addisDate(new Date());
    const yesterday = addDays(today, -1);
    const label = `${template.name_en} · ${yesterday}`;
    const scheduled = await scheduleShift(admin, { stationId: station.id, templateId: template.id, date: yesterday, userIds: [baker.id] });
    expect(scheduled.status).toBe("scheduled");
    expect(scheduled.date).toBe(yesterday);

    const bakerSession = await loginOnce(baker.phone, baker.password, fixture.branchId);
    const make = () => browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    const [bakerContext, adminContext] = [await make(), await make()];
    let nightId = 0;
    let nightClosing = "";
    const noteText = `Oven two ticks ${ingredient.name_en.slice(-6)}`;
    try {
      // --- the admin starts the scheduled Night shift now: the only way into a window that is over ------------------------------
      const boss = await adminContext.newPage();
      await openAs(boss, admin, "en/admin/shifts/roster/");
      await boss.getByLabel("Show the week of").fill(yesterday);
      const startNow = boss.getByRole("button", { name: `Start now: ${station.name_en} · ${template.name_en} · ${yesterday}`, exact: true });
      await expect(startNow).toBeVisible({ timeout: 30_000 });
      await startNow.click();
      const confirm = boss.getByRole("dialog", { name: "Start the shift now" });
      await expect(confirm).toContainText(yesterday);
      await confirm.getByRole("button", { name: "Start shift now", exact: true }).click();
      await expect(boss.getByRole("button", { name: `Start now: ${station.name_en} · ${template.name_en} · ${yesterday}`, exact: true })).toBeHidden({ timeout: 30_000 });

      const opening = await currentShift(admin, station);
      expect(opening?.status).toBe("opening");
      nightId = opening!.id;
      expect(nightId).toBe(scheduled.id);

      // --- the baker: the bar names the Night shift of yesterday's date, the opening count is prefilled from the locked closing ------
      const page = await bakerContext.newPage();
      await openAs(page, bakerSession.api, "en/production/");
      const bar = page.getByRole("region", { name: "Shift", exact: true });
      await expect(bar).toContainText("Opening count in progress", { timeout: 30_000 });
      await expect(bar).toContainText(label);
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(page.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });
      const row = page.getByRole("row").filter({ hasText: ingredient.name_en });
      await expect(row).toContainText("5000", { timeout: 15_000 });
      await page.getByRole("button", { name: "Nothing changed", exact: true }).click();
      await expect(page.getByLabel(`Counted: ${ingredient.name_en}`)).toHaveValue("5000", { timeout: 15_000 });
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      await page.getByRole("dialog", { name: "Review the count" }).getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(page.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });
      expect((await currentShift(admin, station))?.status).toBe("open");

      // --- the baker writes a handover note and closes: nothing changed, so the count locks at once and shows its hash --------------
      await page.goto("en/production/");
      await expect(bar).toContainText("Shift open", { timeout: 30_000 });
      await expect(bar).toContainText(label);
      await bar.getByRole("button", { name: "Close shift", exact: true }).click();
      await page.getByRole("dialog", { name: "Close the shift" }).getByRole("button", { name: "Close shift", exact: true }).click();
      await expect(bar).toContainText("Closing count in progress", { timeout: 15_000 });
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(page.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });
      await page.getByLabel("Note for the next shift").fill(noteText);
      await page.getByRole("button", { name: "Add note", exact: true }).click();
      await expect(page.getByRole("region", { name: "Handover notes" })).toContainText(noteText, { timeout: 15_000 });
      await page.getByRole("button", { name: "Nothing changed", exact: true }).click();
      await expect(page.getByLabel(`Counted: ${ingredient.name_en}`)).toHaveValue("5000", { timeout: 15_000 });
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      await page.getByRole("dialog", { name: "Review the count" }).getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(page.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });

      const nightCounts = (await admin.get<{ data: CountView[] }>(`/shifts/${nightId}/counts`)).data.filter((c) => c.location.id === location.id);
      const closing = nightCounts.find((c) => c.type === "closing" && c.status === "locked");
      expect(closing).toBeTruthy();
      nightClosing = closing!.lines.find((l) => l.item.id === ingredient.id)!.counted_qty!;
      const hash = closing!.chain!.payload_hash;
      await expect(page.getByText(hash)).toBeVisible();

      // --- the next shift: "Start shift" opens with the Night closing values and the note in the handover panel ----------------------
      expect(await currentShift(admin, station)).toBeNull();
      await page.goto("en/production/");
      await expect(bar).toContainText("No shift is running", { timeout: 30_000 });
      await bar.getByRole("button", { name: "Start shift", exact: true }).click();
      await expect(bar).toContainText("Opening count in progress", { timeout: 15_000 });
      await bar.getByRole("link", { name: "Open the count" }).click();
      await expect(page.getByRole("heading", { name: "Shift count", level: 1 })).toBeVisible({ timeout: 30_000 });
      const handover = page.getByRole("region", { name: "Handover from the last shift" });
      await expect(handover).toContainText(noteText, { timeout: 30_000 });
      await expect(handover).toContainText(hash.slice(0, 8));
      await expect(page.getByRole("row").filter({ hasText: ingredient.name_en })).toContainText("5000", { timeout: 15_000 });
      await page.getByRole("button", { name: "Nothing changed", exact: true }).click();
      await page.getByRole("button", { name: "Review and submit", exact: true }).click();
      await page.getByRole("dialog", { name: "Review the count" }).getByRole("button", { name: "Submit count", exact: true }).click();
      await expect(page.getByText("Approved and locked")).toBeVisible({ timeout: 30_000 });
    } finally {
      await bakerContext.close();
      await adminContext.close();
    }

    // --- the API: dates are the local start dates, and the prefill and the chain cross the boundary --------------------------------
    const night = await admin.get<ShiftView>(`/shifts/${nightId}`);
    expect(night.date).toBe(yesterday);
    expect(addisDate(new Date(night.opened_at!))).toBe(today);
    expect(Date.parse(night.starts_at!)).toBe(Date.parse(`${yesterday}T22:00:00+03:00`));
    expect(night.template?.name_en).toBe(template.name_en);

    const next = await currentShift(admin, station);
    expect(next?.status).toBe("open");
    expect(next!.id).not.toBe(nightId);
    const following = await admin.get<ShiftView>(`/shifts/${next!.id}`);
    // an ad-hoc shift is dated by when it was opened; one that matched the Night window is dated by the day the window started
    const expectedDate = following.origin === "started" && following.template === null ? addisDate(new Date(following.opened_at!)) : addisDate(new Date(following.starts_at!));
    expect(following.date).toBe(expectedDate);
    expect([today, yesterday]).toContain(following.date);

    const listed = (await admin.get<{ data: { id: number }[] }>("/shifts", { station_id: station.id, from: yesterday, to: today })).data.map((s) => s.id);
    expect(listed).toContain(nightId);
    expect(listed).toContain(next!.id);

    const opening = (await admin.get<{ data: CountView[] }>(`/shifts/${next!.id}/counts`)).data.find((c) => c.location.id === location.id && c.type === "opening");
    const carried = opening?.lines.find((l) => l.item.id === ingredient.id);
    expect(carried).toBeTruthy();
    expect(scaled(carried!.carried_qty!)).toBe(scaled(nightClosing));
    expect(scaled(nightClosing)).toBe(scaled("5000"));

    // the Night closing's previous hash is the payload hash of the link before it (its own opening count), and the chain verifies
    const chain = await admin.get<{ data: { count_id: number; chain_seq: number; previous_hash: string | null; payload_hash: string }[] }>(`/locations/${location.id}/chain`, { limit: 50 });
    const nightCounts = (await admin.get<{ data: CountView[] }>(`/shifts/${nightId}/counts`)).data.filter((c) => c.location.id === location.id);
    const closingLink = chain.data.find((link) => link.count_id === nightCounts.find((c) => c.type === "closing" && c.status === "locked")!.id)!;
    const before = chain.data.find((link) => link.chain_seq === closingLink.chain_seq - 1)!;
    expect(closingLink.previous_hash).toBe(before.payload_hash);
    const verified = await admin.get<{ ok: boolean; failures: unknown[] }>(`/locations/${location.id}/chain/verify`);
    expect(verified.ok).toBe(true);
    expect(verified.failures).toEqual([]);
    await ensureOpenShift(admin, station);
  });
});
