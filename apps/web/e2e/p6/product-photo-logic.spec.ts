import { expect, test } from "@playwright/test";
import type { ProductImageView } from "../../src/lib/api/types";
import { fitWithin, PRODUCT_PHOTO, RECEIPT_PHOTO } from "../../src/lib/images/downscale";
import { clampView, clampZoom, coverSize, cropFields, cropOf, imageStyle, initialView, MAX_ZOOM, MIN_CROP, MIN_ZOOM, panBy, qualityOf, SHARP_CROP, sourceRect, zoomAt } from "../../src/lib/images/frame";
import { frameSize, initialsOf, mediaUrl, placeholderIcon, RENDITIONS, sizesFor, srcSetOf } from "../../src/lib/images/productImage";

// Phase 6 steps 6.2a and 6.4b, under p6-product-photo (docs/phases/phase-6-plan.md 6.2a, 6.4b and section 7): what ProductImage puts on
// the page, and the photo card's framing maths (at the end), as pure
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

// Step 6.4b (phase-6 plan 6.4b; fresh-counter.md 4.3 "Photo card"): the framing step's maths, lib/images/frame.ts. The admin moves and zooms
// the photo inside a fixed 4:3 frame; what the server is sent is the source rectangle in the pixels of the file the browser uploads (the
// photo shrunk to 2400 px on its long side, lib/images/downscale.ts), always exactly 4:3 (4k x 3k) and inside the photo, so the server's
// snap (CropRect, step 6.1a) keeps it as it is. The quality line reads that rectangle: sharp from 1200 x 900, soft from 800 x 600 (the
// server's minimum), refused below.
test.describe("product photo: framing", () => {
  const PHOTO = { width: 1600, height: 1200 };
  const WIDE = { width: 2400, height: 1000 };
  const TALL = { width: 1000, height: 2000 };

  test("at 1x a 4:3 photo is framed whole, and the frame covers it exactly", () => {
    const view = initialView(PHOTO);
    expect(view).toEqual({ zoom: 1, cx: 800, cy: 600 });
    expect(sourceRect(PHOTO, view)).toEqual({ x: 0, y: 0, width: 1600, height: 1200 });
    expect(imageStyle(PHOTO, view)).toEqual({ left: 0, top: 0, width: 100, height: 100 });
  });

  test("a photo of another shape starts on its largest centred 4:3 rectangle", () => {
    expect(coverSize(WIDE)).toEqual({ width: 4000 / 3, height: 1000 });
    expect(sourceRect(WIDE, initialView(WIDE))).toEqual({ x: 534, y: 1, width: 1332, height: 999 });
    expect(coverSize(TALL)).toEqual({ width: 1000, height: 750 });
    expect(sourceRect(TALL, initialView(TALL))).toEqual({ x: 0, y: 625, width: 1000, height: 750 });
    // the img is drawn larger than the frame on its long side and moved so the rectangle is what shows
    const wide = imageStyle(WIDE, initialView(WIDE));
    expect(wide.width).toBeCloseTo(180, 6);
    expect(wide.height).toBeCloseTo(100, 6);
    expect(wide.left).toBeCloseTo(-40, 6);
    expect(wide.top).toBeCloseTo(0, 6);
  });

  test("zooming in frames a smaller rectangle around the same centre", () => {
    const view = zoomAt(PHOTO, initialView(PHOTO), 2);
    expect(view).toEqual({ zoom: 2, cx: 800, cy: 600 });
    expect(sourceRect(PHOTO, view)).toEqual({ x: 400, y: 300, width: 800, height: 600 });
    expect(imageStyle(PHOTO, view)).toEqual({ left: -50, top: -50, width: 200, height: 200 });
    // 1.2x, as the board shows it: 1333.3 x 1000 rounds down to the 4:3 rectangle of whole pixels inside it
    expect(sourceRect(PHOTO, zoomAt(PHOTO, initialView(PHOTO), 1.2))).toEqual({ x: 134, y: 101, width: 1332, height: 999 });
  });

  test("the zoom stays between 1x and 3x", () => {
    expect(clampZoom(0.5)).toBe(MIN_ZOOM);
    expect(clampZoom(9)).toBe(MAX_ZOOM);
    expect(clampZoom(Number.NaN)).toBe(MIN_ZOOM);
    expect([MIN_ZOOM, MAX_ZOOM]).toEqual([1, 3]);
    expect(zoomAt(PHOTO, initialView(PHOTO), 5).zoom).toBe(3);
    expect(sourceRect(PHOTO, zoomAt(PHOTO, initialView(PHOTO), 5))).toEqual({ x: 534, y: 400, width: 532, height: 399 });
  });

  test("zooming at a point (a pinch, a trackpad) keeps the point under the fingers where it was", () => {
    // at 1x the frame's top-left quarter point shows source (400, 300); after 2x at that point it still does
    const view = zoomAt(PHOTO, initialView(PHOTO), 2, { x: 0.25, y: 0.25 });
    const crop = cropOf(PHOTO, view);
    expect(crop.x + 0.25 * crop.width).toBeCloseTo(400, 6);
    expect(crop.y + 0.25 * crop.height).toBeCloseTo(300, 6);
    expect(view).toEqual({ zoom: 2, cx: 600, cy: 450 });
  });

  test("a drag moves the photo with the pointer, in the frame's pixels turned into the photo's", () => {
    // the frame is 400 px wide on screen and shows 800 source pixels at 2x: 2 source pixels a screen pixel
    const start = zoomAt(PHOTO, initialView(PHOTO), 2);
    const moved = panBy(PHOTO, start, 400, 50, -25);
    // the photo follows the pointer right and up, so the rectangle moves left and down
    expect(moved).toEqual({ zoom: 2, cx: 700, cy: 650 });
    expect(sourceRect(PHOTO, moved)).toEqual({ x: 300, y: 350, width: 800, height: 600 });
  });

  test("the photo cannot be dragged off the frame: the rectangle is held inside the photo", () => {
    const start = zoomAt(PHOTO, initialView(PHOTO), 2);
    expect(sourceRect(PHOTO, panBy(PHOTO, start, 400, 5000, 5000))).toEqual({ x: 0, y: 0, width: 800, height: 600 });
    expect(sourceRect(PHOTO, panBy(PHOTO, start, 400, -5000, -5000))).toEqual({ x: 800, y: 600, width: 800, height: 600 });
    // at 1x on a 4:3 photo there is nothing to move
    expect(panBy(PHOTO, initialView(PHOTO), 400, 120, 80)).toEqual(initialView(PHOTO));
    // a wide photo moves sideways only
    expect(sourceRect(WIDE, panBy(WIDE, initialView(WIDE), 400, -5000, 300))).toEqual({ x: 1068, y: 1, width: 1332, height: 999 });
    // zooming out near an edge pulls the rectangle back inside
    const edge = panBy(PHOTO, zoomAt(PHOTO, initialView(PHOTO), 3), 400, 5000, 5000);
    expect(clampView(PHOTO, { ...edge, zoom: 1 })).toEqual(initialView(PHOTO));
    expect(zoomAt(PHOTO, edge, 1)).toEqual(initialView(PHOTO));
  });

  test("whatever the view, the rectangle is whole pixels, exactly 4:3 and inside the photo", () => {
    const sources = [PHOTO, WIDE, TALL, { width: 2400, height: 1800 }, { width: 1999, height: 1337 }, { width: 801, height: 601 }];
    let seed = 7;
    const next = () => {
      seed = (seed * 48271) % 2147483647;
      return seed / 2147483647;
    };
    for (const source of sources) {
      for (let i = 0; i < 200; i += 1) {
        const zoomed = zoomAt(source, initialView(source), 1 + next() * 2.5, { x: next(), y: next() });
        const view = panBy(source, zoomed, 300 + next() * 300, (next() - 0.5) * 900, (next() - 0.5) * 900);
        const rect = sourceRect(source, view);
        expect(Number.isInteger(rect.x) && Number.isInteger(rect.y) && Number.isInteger(rect.width) && Number.isInteger(rect.height)).toBe(true);
        expect(rect.width * 3).toBe(rect.height * 4);
        expect(rect.x).toBeGreaterThanOrEqual(0);
        expect(rect.y).toBeGreaterThanOrEqual(0);
        expect(rect.x + rect.width).toBeLessThanOrEqual(source.width);
        expect(rect.y + rect.height).toBeLessThanOrEqual(source.height);
      }
    }
  });

  test("the quality line: sharp from 1200 x 900, soft from 800 x 600, too small below (the server's minimum)", () => {
    expect([SHARP_CROP, MIN_CROP]).toEqual([
      { width: 1200, height: 900 },
      { width: 800, height: 600 },
    ]);
    expect(qualityOf({ width: 2400, height: 1800 })).toBe("sharp");
    expect(qualityOf({ width: 1200, height: 900 })).toBe("sharp");
    expect(qualityOf({ width: 1196, height: 897 })).toBe("soft");
    expect(qualityOf({ width: 800, height: 600 })).toBe("soft");
    expect(qualityOf({ width: 796, height: 597 })).toBe("too_small");
    expect(qualityOf({ width: 0, height: 0 })).toBe("too_small");
    // the fixture at 2x is the server's minimum exactly; a 640 x 480 photo is too small at every zoom
    expect(qualityOf(sourceRect(PHOTO, zoomAt(PHOTO, initialView(PHOTO), 2)))).toBe("soft");
    expect(qualityOf(sourceRect(PHOTO, zoomAt(PHOTO, initialView(PHOTO), 2.01)))).toBe("too_small");
    const small = { width: 640, height: 480 };
    expect(sourceRect(small, initialView(small))).toEqual({ x: 0, y: 0, width: 640, height: 480 });
    expect(qualityOf(sourceRect(small, initialView(small)))).toBe("too_small");
  });

  test("the rectangle goes to the server as the four crop fields of POST /items/{item}/image", () => {
    expect(cropFields({ x: 134, y: 101, width: 1332, height: 999 })).toEqual({ crop_x: "134", crop_y: "101", crop_width: "1332", crop_height: "999" });
  });

  test("a product photo is shrunk to 2400 px on its long side as JPEG 0.9; a receipt keeps 1600 px and 0.82", () => {
    expect(PRODUCT_PHOTO).toEqual({ maxEdge: 2400, quality: 0.9 });
    expect(RECEIPT_PHOTO).toEqual({ maxEdge: 1600, quality: 0.82 });
    expect(fitWithin(4032, 3024, PRODUCT_PHOTO.maxEdge)).toEqual({ width: 2400, height: 1800 });
    expect(fitWithin(3024, 4032, PRODUCT_PHOTO.maxEdge)).toEqual({ width: 1800, height: 2400 });
    expect(fitWithin(1600, 1200, PRODUCT_PHOTO.maxEdge)).toEqual({ width: 1600, height: 1200 });
    expect(fitWithin(4032, 3024)).toEqual({ width: 1600, height: 1200 });
  });
});
