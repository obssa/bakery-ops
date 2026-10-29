import { expect, test } from "@playwright/test";
import { collectTyped, sweep } from "../support/amharic";

// Phase 5 step 5.4a, the sweep under p5-amharic on planted pages (a browser, no stack): what counts as English left on an Amharic
// screen, and what the allowlist of plan 10.2 D19 lets through.

const page = (body: string) => `<!doctype html><html lang="am"><body><main>${body}</main></body></html>`;

test.describe("amharic sweep", () => {
  test("passes Amharic, numbers, ETB, the order number, typed names and text marked as another language", async ({ page: tab }) => {
    await tab.setContent(
      page(`
        <h1>የአስተናጋጅ ፓድ</h1>
        <p>ትዕዛዝ #12 · 3 × 25.00 ETB</p>
        <p>ደረሰኙን አውርድ (PDF) · CSV ላክ</p>
        <p>Club sandwich ከ Meron T. ለጠረጴዛ T4</p>
        <nav><a lang="en" href="#">English</a><a lang="am" href="#">አማርኛ</a></nav>
        <p hidden>Hidden English</p>
        <p style="display:none">Not shown</p>
        <button aria-label="ዝጋ">×</button>
      `),
    );
    expect(await sweep(tab, ["Club sandwich", "Meron T.", "T4"])).toEqual([]);
  });

  test("reports English text, an English label, a currency word that is not ETB and a typed name in the wrong language", async ({ page: tab }) => {
    await tab.setContent(
      page(`
        <h1 data-testid="title">Waiter pad</h1>
        <p>ትዕዛዝ 12 Ready</p>
        <button aria-label="Close">×</button>
        <input placeholder="Search" />
        <p>25.00 Birr</p>
        <p class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Skip to content</p>
        <p>Kitchen</p>
        <p>ብዛት 3 g · gold</p>
      `),
    );
    const gaps = await sweep(tab, ["ኩሽና", "g"]);
    expect(gaps.map((g) => g.text)).toEqual([
      "Waiter pad",
      "ትዕዛዝ 12 Ready",
      "25.00 Birr",
      "Skip to content",
      "Kitchen",
      "ብዛት 3 g · gold",
      'aria-label="Close"',
      'placeholder="Search"',
    ]);
    expect(gaps[0].where).toBe("main > h1[data-testid=title]");
  });

  test("refuses to sweep a page that is not the Amharic one", async ({ page: tab }) => {
    await tab.setContent("<!doctype html><html lang=\"en\"><body><main>Hello</main></body></html>");
    await expect(sweep(tab, [])).rejects.toThrow(/the page is the Amharic one/);
  });

  test("collects the typed names a screen may show in Amharic, never the English twin of a bilingual name", () => {
    const names = new Set<string>();
    collectTyped(
      {
        data: [
          { id: 1, name_en: "Club sandwich", name_am: "ክለብ ሳንድዊች", sku: "P-SND-CLUB", status: "open", unit: { code: "pcs", name_en: "Piece" } },
          { id: 2, name: "Meron Tadesse", roles: ["manager"], note: "E2E note" },
        ],
      },
      names,
    );
    expect([...names].sort()).toEqual(["E2E note", "Meron Tadesse", "P-SND-CLUB", "pcs"]);
  });
});
