import { expect, type Page } from "@playwright/test";
import type { Api } from "./api";

// The Amharic screen sweep of step 5.4a (p5-amharic): on a page of /app/am/, every visible text node, and every label a person reads or
// hears (aria-label, placeholder, title, alt), must hold no Latin letter once the allowed text is taken out (plan 10.2 D19):
//   - numbers and the order number format (digits, `#`), which hold no letter anyway;
//   - ALLOWED_WORDS: the currency and the file formats and codes the dictionary keeps as they are (docs/i18n/glossary.md);
//   - the names the admin and the staff typed (typedNames: products, stations, people, channels, units, tables, devices, notes), in the
//     form an Amharic screen shows: `name_am` and the other `*_am` fields, never `*_en`, so a screen that shows the English name of a
//     bilingual row is a gap;
//   - text inside an element marked with another language (`lang="en"`): the language switch names English in its own script, and
//     the catalog shows an item's other-language name beside it on purpose.
// A word joins ALLOWED_WORDS only with its reason, in the glossary too. Never add one to hide a missing translation.

/** Words that stay as they are on an Amharic screen, each with its reason (docs/i18n/glossary.md, "Words that stay in Latin script"). */
export const ALLOWED_WORDS = [
  "ETB", // the currency code (D19)
  "PDF", // a file format, after its Amharic label: "ደረሰኙን አውርድ (PDF)"
  "CSV", // a file format: "CSV ላክ"
  "Excel", // a file format: "Excel ላክ"
  "SKU", // a code, after its Amharic label: "የዕቃ ኮድ (SKU)"
  "TIN", // the tax number, after its Amharic label: "የግብር ከፋይ መለያ ቁጥር (TIN)"
] as const;

export interface LatinGap {
  text: string;
  where: string;
}

/** What `sweep` runs inside the page; self-contained, because Playwright sends it to the browser as source. */
function sweepInPage({ names, words }: { names: string[]; words: string[] }): LatinGap[] {
  const latin = /[A-Za-z]/;
  // One pattern for every allowed piece, longest first, each a whole word: a unit typed as "g" never eats the g of an English word.
  const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pieces = [...names, ...words].filter((s) => s.length > 0).sort((a, b) => b.length - a.length).map(escape);
  const allowed = new RegExp(`(^|[^A-Za-z])(${pieces.join("|")})(?=$|[^A-Za-z])`, "g");
  const left = (text: string): string => text.replace(allowed, "$1 ");
  const otherLanguage = (el: Element): boolean => {
    const marked = el.closest("[lang]");
    return marked !== null && marked.getAttribute("lang") !== "am";
  };
  const describe = (el: Element): string => {
    const parts: string[] = [];
    for (let e: Element | null = el, depth = 0; e && e !== document.body && depth < 4; e = e.parentElement, depth++) {
      const testId = e.getAttribute("data-testid");
      const role = e.getAttribute("role");
      parts.unshift(`${e.tagName.toLowerCase()}${e.id ? `#${e.id}` : ""}${testId ? `[data-testid=${testId}]` : ""}${role ? `[role=${role}]` : ""}`);
    }
    return parts.join(" > ");
  };
  const gaps: LatinGap[] = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.nodeValue ?? "";
    if (!latin.test(text)) continue;
    const el = node.parentElement;
    if (!el || el.closest("script, style, noscript, template") || otherLanguage(el)) continue;
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
    if (latin.test(left(text))) gaps.push({ text: text.trim().slice(0, 120), where: describe(el) });
  }
  for (const el of Array.from(document.body.querySelectorAll("[aria-label], [placeholder], [title], img[alt]"))) {
    if (otherLanguage(el) || !el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
    for (const attribute of ["aria-label", "placeholder", "title", "alt"]) {
      const value = el.getAttribute(attribute);
      if (value && latin.test(value) && latin.test(left(value))) gaps.push({ text: `${attribute}="${value.slice(0, 120)}"`, where: describe(el) });
    }
  }
  return gaps;
}

/** The Latin text left on the page, outside the allowlist; an empty list on a clean Amharic screen. */
export async function sweep(page: Page, names: string[]): Promise<LatinGap[]> {
  expect(await page.evaluate(() => document.documentElement.lang), "the page is the Amharic one").toBe("am");
  return page.evaluate(sweepInPage, { names, words: [...ALLOWED_WORDS] });
}

/**
 * Waits until the page stops changing: its text is the same twice in a row 400 ms apart (a screen first shows its loading line, then
 * its rows), at most `budgetMs`. Polling screens keep polling, but their text holds still between two answers.
 */
export async function settle(page: Page, budgetMs = 8_000): Promise<void> {
  await page.locator("main").first().waitFor({ state: "visible", timeout: 30_000 });
  const deadline = Date.now() + budgetMs;
  let before = await page.evaluate(() => document.body.innerText);
  let same = 0;
  while (Date.now() < deadline && same < 2) {
    await page.waitForTimeout(400);
    const now = await page.evaluate(() => document.body.innerText);
    same = now === before ? same + 1 : 0;
    before = now;
  }
}

// The fields whose text a person typed: names, codes, notes. `*_en` is left out on purpose (see above).
const TYPED_KEY = /^(name|code|sku|label|fingerprint|reference|note|notes|comment|address|business_name|tin|phone|invoice_no|symbol|.+_am)$/;

/** Every string of `value` under a typed field, at any depth. */
export function collectTyped(value: unknown, into: Set<string>, key = ""): void {
  if (typeof value === "string") {
    if (TYPED_KEY.test(key) && /[A-Za-z]/.test(value) && value.trim().length > 0) into.add(value.trim());
    return;
  }
  if (Array.isArray(value)) {
    for (const item of value) collectTyped(item, into, key);
    return;
  }
  if (value && typeof value === "object") {
    for (const [k, v] of Object.entries(value)) collectTyped(v, into, k);
  }
}

// The lists that hold typed names, read as an admin of the branch.
const TYPED_SOURCES = [
  "/users",
  "/stations",
  "/locations",
  "/items",
  "/categories",
  "/units",
  "/payment-channels",
  "/suppliers",
  "/dining-tables",
  "/variance-reasons",
  "/devices",
  "/shift-templates",
  "/receipt-settings",
  "/orders",
  "/payments",
  "/purchases",
  "/transfers",
  "/production/requests",
  "/stock-counts",
  "/shifts",
];

/** The names the branch's people typed, longest first (so "Club sandwich" goes before "Club"). */
export async function typedNames(admin: Api): Promise<string[]> {
  const names = new Set<string>();
  for (const path of TYPED_SOURCES) {
    try {
      collectTyped(await admin.get<unknown>(path), names);
    } catch {
      // a list this branch cannot read (or one that needs a filter) adds nothing
    }
  }
  return [...names].sort((a, b) => b.length - a.length);
}
