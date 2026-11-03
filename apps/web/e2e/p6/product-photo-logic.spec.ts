import { expect, test } from "@playwright/test";
import type { ProductImageView } from "../../src/lib/api/types";
import { frameSize, initialsOf, mediaUrl, placeholderIcon, RENDITIONS, sizesFor, srcSetOf } from "../../src/lib/images/productImage";

// Phase 6 step 6.2a, under p6-product-photo (docs/phases/phase-6-plan.md 6.2a and section 7): what ProductImage puts on the page, as pure
// functions (no browser, no stack). The API (step 6.1a, ADR 0022) answers a product's photo as {thumb, card, large, width, height}: three
// content-addressed paths without a host, and the size of the large rendition, which the server never upscales (a crop of 1000 x 750 keeps
// 1000 x 750 as large; 800 x 600 is the smallest it accepts).

const SHA = "5124991d24bf2e045c91dde2f85f03b3bf240e9062c34f2ecd5d4ef2b69826ba";

function photo(width: number, height: number, ext: "webp" | "jpg" = "webp"): ProductImageView {
  const path = (rendition: string) => `/api/v1/media/items/${SHA}/${rendition}.${ext}`;
  return { thumb: path("thumb"), card: path("card"), large: path("large"), width, height };
}

const at = (rendition: string, ext = "webp") => `/api/v1/media/items/${SHA}/${rendition}.${ext}`;

test.describe("product photo: srcSet", () => {
  test("lists thumb, card and large with their widths, the large one at the width the API reports", () => {
    expect(srcSetOf(photo(1600, 1200), "")).toBe(`${at("thumb")} 320w, ${at("card")} 800w, ${at("large")} 1600w`);
  });

  test("a large rendition smaller than 1600 px keeps its own width, never an upscaled one", () => {
    expect(srcSetOf(photo(1000, 750), "")).toBe(`${at("thumb")} 320w, ${at("card")} 800w, ${at("large")} 1000w`);
    expect(srcSetOf(photo(1201, 901, "jpg"), "")).toBe(`${at("thumb", "jpg")} 320w, ${at("card", "jpg")} 800w, ${at("large", "jpg")} 1201w`);
  });

  test("a large rendition no wider than the card is left out, so no width is listed twice", () => {
    expect(srcSetOf(photo(800, 600), "")).toBe(`${at("thumb")} 320w, ${at("card")} 800w`);
  });

  test("never claims more pixels than the photo has, even below the server's minimum", () => {
    // the API refuses a crop under 800 x 600 (catalog.image.too_small), so this only happens with a hand-made payload; the widths still
    // never exceed the photo's own
    expect(srcSetOf(photo(640, 480), "")).toBe(`${at("thumb")} 320w, ${at("card")} 640w`);
  });

  test("paths are put on the API origin, as every API call is; an absolute URL is kept as it is", () => {
    expect(mediaUrl(at("card"), "http://localhost:8000")).toBe(`http://localhost:8000${at("card")}`);
    expect(mediaUrl(at("card"), "http://localhost:8000/")).toBe(`http://localhost:8000${at("card")}`);
    expect(mediaUrl(at("card"), "")).toBe(at("card"));
    expect(mediaUrl(`https://cdn.example.test${at("card")}`, "http://localhost:8000")).toBe(`https://cdn.example.test${at("card")}`);
    expect(srcSetOf(photo(1600, 1200), "http://localhost:8000").split(", ")[0]).toBe(`http://localhost:8000${at("thumb")} 320w`);
  });

  test("the img's width and height are the rendition's own, 4:3, so the frame holds its place before the photo arrives", () => {
    expect(RENDITIONS).toEqual({ thumb: { width: 320, height: 240 }, card: { width: 800, height: 600 }, large: { width: 1600, height: 1200 } });
    expect(frameSize(photo(1600, 1200), "thumb")).toEqual({ width: 320, height: 240 });
    expect(frameSize(photo(1600, 1200), "card")).toEqual({ width: 800, height: 600 });
    expect(frameSize(photo(1600, 1200), "large")).toEqual({ width: 1600, height: 1200 });
    expect(frameSize(photo(1000, 750), "large")).toEqual({ width: 1000, height: 750 });
    expect(frameSize(photo(640, 480), "card")).toEqual({ width: 640, height: 480 });
  });

  test("each size tells the browser how wide it is shown, so a pad never downloads the large rendition for a card", () => {
    expect(sizesFor("thumb")).toBe("64px");
    expect(sizesFor("card")).toBe("(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 280px");
    expect(sizesFor("large")).toBe("(max-width: 640px) 100vw, 480px");
  });
});

test.describe("product photo: the placeholder", () => {
  // the demo bakery's product categories (Database\Seeders\Demo\DemoBakery::CATEGORIES, kind product), in both languages
  const DEMO: [string, string, ReturnType<typeof placeholderIcon>][] = [
    ["Breads", "ዳቦ", "bread"],
    ["Cakes & pastries", "ኬክና ፓስትሪ", "cake"],
    ["Sandwiches", "ሳንድዊች", "sandwich"],
    ["Pizza", "ፒዛ", "pizza"],
    ["Hot drinks", "ትኩስ መጠጦች", "cup"],
    ["Cold drinks", "ቀዝቃዛ መጠጦች", "glass"],
  ];

  for (const [en, am, icon] of DEMO) {
    test(`"${en}" (${am}) shows the ${icon} icon in either language`, () => {
      expect(placeholderIcon(en)).toBe(icon);
      expect(placeholderIcon(am)).toBe(icon);
      expect(placeholderIcon(en.toUpperCase())).toBe(icon);
    });
  }

  test("a product without a category, or with one the icons do not know, shows the plate", () => {
    expect(placeholderIcon(null)).toBe("plate");
    expect(placeholderIcon(undefined)).toBe("plate");
    expect(placeholderIcon("")).toBe("plate");
    expect(placeholderIcon("   ")).toBe("plate");
    expect(placeholderIcon("Salads")).toBe("plate");
    expect(placeholderIcon("ሰላጣ")).toBe("plate");
  });

  test("names a shop may type match too, and a short word only as a whole word", () => {
    expect(placeholderIcon("Coffee & tea")).toBe("cup");
    expect(placeholderIcon("Juices")).toBe("glass");
    expect(placeholderIcon("Burgers")).toBe("sandwich");
    expect(placeholderIcon("Buns and rolls")).toBe("bread");
    expect(placeholderIcon("Desserts")).toBe("cake");
    // "hot" and "tea" inside a longer word are not hot drinks
    expect(placeholderIcon("Photos")).toBe("plate");
    expect(placeholderIcon("Steaks")).toBe("plate");
  });
});

test.describe("product photo: initials", () => {
  test("English: the first letter of the first two words, capitals; a word in brackets or a number is skipped", () => {
    expect(initialsOf("Bottled water 0.5 L")).toBe("BW");
    expect(initialsOf("Croissant")).toBe("C");
    expect(initialsOf("black forest cake (slice)")).toBe("BF");
    expect(initialsOf("Tea (shai)")).toBe("T");
    expect(initialsOf("Cola 300 ml")).toBe("CM");
    expect(initialsOf("  Macchiato  ")).toBe("M");
  });

  test("Amharic: the first fidel of the first two words, the possessive የ dropped from the front of a word", () => {
    expect(initialsOf("ክሮሳንት")).toBe("ክ");
    expect(initialsOf("ብላክ ፎረስት ኬክ (ቁራጭ)")).toBe("ብፎ");
    expect(initialsOf("የማንጎ ጭማቂ")).toBe("ማጭ");
    expect(initialsOf("የታሸገ ውሃ 0.5 ሊትር")).toBe("ታው");
    expect(initialsOf("ሻይ")).toBe("ሻ");
    // a word that is only የ keeps it
    expect(initialsOf("የ ቡና")).toBe("የቡ");
  });

  test("a name with no letters, or only a bracket, still gives something sensible", () => {
    expect(initialsOf("")).toBe("");
    expect(initialsOf("300")).toBe("");
    expect(initialsOf("(ቁራጭ)")).toBe("ቁ");
  });
});
