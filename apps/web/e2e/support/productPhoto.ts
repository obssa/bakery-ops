import { readFileSync } from "node:fs";
import path from "node:path";
import type { Locator, Page } from "@playwright/test";
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

/**
 * Drops a file on `target` as the browser does when one is dragged from the desktop (step 6.4b's dropzone): dragenter and dragover with
 * the file in a DataTransfer, then, unless `hold`, the drop. With `hold` the drag stays over the target, for the "Drop to upload" look.
 */
export async function dropFile(target: Locator, file: { name: string; mimeType: string; buffer: Buffer }, options: { hold?: boolean } = {}): Promise<void> {
  const dataTransfer = await target.page().evaluateHandle(
    ({ name, mimeType, base64 }) => {
      const binary = atob(base64);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
      const transfer = new DataTransfer();
      transfer.items.add(new File([bytes], name, { type: mimeType }));
      return transfer;
    },
    { name: file.name, mimeType: file.mimeType, base64: file.buffer.toString("base64") },
  );
  await target.dispatchEvent("dragenter", { dataTransfer });
  await target.dispatchEvent("dragover", { dataTransfer });
  if (!options.hold) await target.dispatchEvent("drop", { dataTransfer });
}

/** The fixture as a file to drop or to choose. */
export function fixtureFile(file: string = FIXTURE_PHOTO): { name: string; mimeType: string; buffer: Buffer } {
  return { name: path.basename(file), mimeType: "image/jpeg", buffer: readFileSync(file) };
}

/** The menu's ETag as `api` reads it now (GET /menu; the shell polls it with If-None-Match, ADR 0009). */
export async function menuEtag(api: Api): Promise<string> {
  const response = await fetch(`${api.origin}/api/v1/menu`, {
    headers: { Accept: "application/json", "X-Device-Id": api.device, ...(api.token ? { Authorization: `Bearer ${api.token}` } : {}) },
  });
  if (!response.ok) throw new Error(`GET /menu answered ${response.status}`);
  return response.headers.get("ETag") ?? "";
}

/** What the public media route answers for a rendition's path now (200 while a live row names it, 404 once it is replaced or removed). */
export async function mediaStatus(api: Api, mediaPath: string): Promise<number> {
  return (await fetch(`${api.origin}${mediaPath}`)).status;
}
