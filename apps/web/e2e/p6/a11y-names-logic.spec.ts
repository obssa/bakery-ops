import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";

// Step 6.5 (ADR 0023), beside the admin pass of a11y-admin.spec.ts: the five names of 6.4c and 6.4d hold the words their controls show, in
// both languages, as axe's label-content-name-mismatch reads them (axe-core 4.14: the visible words must be a contiguous run of the name's
// words). The pass reads only what the seeded screens draw, and it does not draw all of these: with every shift open there is no "Open the
// count" on the Shifts page, and the reconciliation's drill needs a photo channel with a payment lacking its photo. So they are checked
// here from the dictionaries, with no page.

const MESSAGES = { en, am } as const;

/** The words of a text as axe compares them: NFKD, no parenthetical, only letters and digits, split by the word segmenter. */
function words(text: string): string[] {
  const plain = text.normalize("NFKD").replace(/\([^()]*\)/g, "").replace(/[^\p{L}\p{N}]/gu, " ").toLowerCase();
  const segments = new Intl.Segmenter(undefined, { granularity: "word" }).segment(plain);
  return Array.from(segments, (part) => part.segment.trim()).filter(Boolean);
}

function holds(name: string, shown: string): boolean {
  const haystack = words(name);
  const needle = words(shown);
  return haystack.some((_, start) => needle.every((word, index) => haystack[start + index] === word));
}

function fill(message: string, values: Record<string, string>): string {
  return message.replace(/\{(\w+)\}/g, (whole, key: string) => values[key] ?? whole);
}

test.describe("a11y: names that hold the words their controls show", () => {
  for (const locale of ["en", "am"] as const) {
    const t = MESSAGES[locale];
    const label = { label: "Kitchen · Day · 2026-11-04" };
    const no = { no: "22" };
    const cases: { control: string; shown: string; name: string }[] = [
      { control: "Show notes", shown: t.adminShifts.notes.show, name: fill(t.adminShifts.notes.show_named, label) },
      { control: "Hide notes", shown: t.adminShifts.notes.hide, name: fill(t.adminShifts.notes.hide_named, label) },
      { control: "Open the count", shown: t.adminShifts.running.open_count, name: fill(t.adminShifts.running.open_count_named, { station: "Kitchen" }) },
      { control: "the admin's order card", shown: fill(t.kds.order_no, no), name: fill(t.adminOrders.open_order, no) },
      { control: "the admin's Void order", shown: t.adminOrders.void, name: fill(t.adminOrders.void_aria, no) },
      { control: "the reconciliation's drill", shown: "3", name: fill(t.adminPayments.reconciliation.drill, { channel: "Telebirr", day: "2026-11-04", count: "3" }) },
    ];
    for (const { control, shown, name } of cases) {
      test(`${control} (${locale}): "${name}" holds "${shown}"`, () => {
        expect(holds(name, shown)).toBe(true);
      });
    }
  }

  // the check can fail: a suffix glued to the shown word makes another word, which is why the Amharic names were changed
  test('the old Amharic order card "ትዕዛዝ 22ን ክፈት" does not hold "ትዕዛዝ 22"', () => {
    expect(holds("ትዕዛዝ 22ን ክፈት", "ትዕዛዝ 22")).toBe(false);
  });
});
