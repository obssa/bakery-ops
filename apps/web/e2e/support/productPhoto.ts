import { readFileSync } from "node:fs";
import path from "node:path";
import type { Page } from "@playwright/test";
import type { ProductImageView } from "../../src/lib/api/types";
import { uuid, type Api } from "./api";

// Product photos for the p6 flows (phase-6 plan 6.3a and 6.4b; ADR 0022). The fixture is a generated test card (shapes and text, no
// people, no stock photo), 1600 x 1200 and 4:3, so the server keeps it whole as the large rendition and makes the card and the thumb
// from it. It is committed (93 KB) because the admin's photo flow of step 6.4b drops the same file into the dropzone.

export const FIXTURE_PHOTO = path.join(__dirname, "..", "fixtures", "product-photo-1600x1200.jpg");

/** What POST /items/{item}/image answers about the photo it set: the renditions, the large one's size, and who and when. */
export interface StoredProductPhoto extends ProductImageView {
  id: number;
  origin: "upload" | "demo";
}

/** Sets the fixture as a product's photo through the API (multipart, a fresh client_uuid, no crop: the whole 4:3 picture). */
export async function attachPhoto(admin: Api, itemId: number, file: string = FIXTURE_PHOTO): Promise<StoredProductPhoto> {
  const form = new FormData();
  form.append("photo", new Blob([readFileSync(file)], { type: "image/jpeg" }), path.basename(file));
  form.append("client_uuid", uuid());
  const answer = await admin.postForm<{ data: { item_id: number; image: StoredProductPhoto } }>(`/items/${itemId}/image`, form);
  return answer.data.image;
}

/** Removes a product's photo through the API (the row is soft-deleted; its URLs answer 404 from then on). */
export async function removePhoto(admin: Api, itemId: number): Promise<void> {
  await admin.delete(`/items/${itemId}/image`);
}

/** A regular expression matching a URL that ends with this media path (the page puts it on the API origin, as every call). */
export function endsWithPath(mediaPath: string): RegExp {
  return new RegExp(`${mediaPath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`);
}

/**
 * The images on the page that the browser tried and could not draw: complete, without a single pixel. A lazy image the browser has
 * not fetched yet is not complete, so it is not counted.
 */
export async function brokenImages(page: Page): Promise<string[]> {
  return page
    .locator("img")
    .evaluateAll((images) => images.filter((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth === 0).map((img) => (img as HTMLImageElement).currentSrc || (img as HTMLImageElement).src));
}
