import { expect, test } from "@playwright/test";
import type { ProductImageView } from "../../src/lib/api/types";
import { imagesById, thumbStack, unitCount } from "../../src/lib/images/orderThumbs";

// Phase 6 step 6.3b, under p6-product-photo (docs/phases/phase-6-plan.md 6.3b, 10.2 Q7): the product photos the till shows beside an order,
// as pure functions. The web joins them itself from the catalog (GET /items?kind=product, lib/productImages.ts), so the till's list, the
// production board and Today need no change in Orders, Production or Analytics: `imagesById` is that join's map, `thumbStack` the till
// card's stack of up to three round thumbs and "+N", `unitCount` the "4 items" beside it. Offline the map is empty and every thumb is the
// placeholder.

const SHA = "5124991d24bf2e045c91dde2f85f03b3bf240e9062c34f2ecd5d4ef2b69826ba";

function photo(n: number): ProductImageView {
  const path = (rendition: string) => `/api/v1/media/items/${SHA.slice(0, 60)}${String(n).padStart(4, "0")}/${rendition}.webp`;
  return { thumb: path("thumb"), card: path("card"), large: path("large"), width: 1600, height: 1200 };
}

function product(id: number, image?: ProductImageView | null) {
  return { id, kind: "product" as const, ...(image === undefined ? {} : { image }) };
}

function line(id: number, itemId: number, qty = "1.0000") {
  return { id, qty, item: { id: itemId, name_en: `Product ${itemId}`, name_am: `ምርት ${itemId}` } };
}

test.describe("product photos joined from the catalog", () => {
  test("maps each product that has a photo by its id", () => {
    const map = imagesById([product(1, photo(1)), product(2, photo(2))]);
    expect(map.size).toBe(2);
    expect(map.get(1)).toEqual(photo(1));
    expect(map.get(2)?.thumb).toBe(photo(2).thumb);
  });

  test("leaves out a product without a photo, an answer from before photos existed, and anything that is not a product", () => {
    const map = imagesById([product(1, null), product(2), { id: 3, kind: "ingredient" as const, image: photo(3) }, product(4, photo(4))]);
    expect([...map.keys()]).toEqual([4]);
  });

  test("is empty before the catalog was read (offline, or still loading), so every thumb shows its placeholder", () => {
    expect(imagesById(undefined).size).toBe(0);
    expect(imagesById([]).size).toBe(0);
    const { thumbs } = thumbStack([line(1, 7)], imagesById(undefined));
    expect(thumbs).toEqual([{ itemId: 7, name_en: "Product 7", name_am: "ምርት 7", image: null }]);
  });
});

test.describe("the till card's thumb stack", () => {
  test("shows one thumb per product, in the order of the lines, with each product's photo or null for its placeholder", () => {
    const images = imagesById([product(10, photo(10)), product(12, photo(12))]);
    const { thumbs, more } = thumbStack([line(1, 10), line(2, 11), line(3, 12)], images);
    expect(thumbs.map((t) => t.itemId)).toEqual([10, 11, 12]);
    expect(thumbs.map((t) => t.image?.thumb ?? null)).toEqual([photo(10).thumb, null, photo(12).thumb]);
    expect(more).toBe(0);
  });

  test("a product on two lines (two notes, or added again later) is one thumb", () => {
    const { thumbs, more } = thumbStack([line(1, 10), line(2, 10, "2.0000"), line(3, 11)], new Map());
    expect(thumbs.map((t) => t.itemId)).toEqual([10, 11]);
    expect(more).toBe(0);
  });

  test("at most three thumbs; the products beyond them are counted for the +N", () => {
    const lines = [line(1, 1), line(2, 2), line(3, 3), line(4, 4), line(5, 5), line(6, 4)];
    const { thumbs, more } = thumbStack(lines, new Map());
    expect(thumbs.map((t) => t.itemId)).toEqual([1, 2, 3]);
    expect(more).toBe(2);
    expect(thumbStack(lines, new Map(), 5)).toMatchObject({ more: 0 });
  });

  test("an order without lines has no stack", () => {
    expect(thumbStack([], new Map())).toEqual({ thumbs: [], more: 0 });
  });
});

test.describe("how many items an order holds", () => {
  test("adds the quantities as decimal strings: a whole number of units is a count", () => {
    expect(unitCount([line(1, 1, "2.0000"), line(2, 2, "1.0000"), line(3, 3, "1")])).toEqual({ whole: true, count: 4, text: "4" });
    expect(unitCount([line(1, 1, "1.0000")])).toEqual({ whole: true, count: 1, text: "1" });
  });

  test("a part of a unit (a product sold by weight) is shown as the quantity, never rounded", () => {
    expect(unitCount([line(1, 1, "0.2500"), line(2, 2, "1.0000")])).toEqual({ whole: false, count: null, text: "1.25" });
  });

  test("no lines is no items", () => {
    expect(unitCount([])).toEqual({ whole: true, count: 0, text: "0" });
  });
});
