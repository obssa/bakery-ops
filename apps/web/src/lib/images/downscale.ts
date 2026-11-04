// Photos are shrunk in the browser before they are uploaded: a phone's 12 MP photo is several megabytes on a slow line.
//
// - Receipt photos (step 4.5b, docs/phases/phase-4-plan.md section 6 item 5): the server keeps a longest side of 1600 px anyway
//   (Shared\Files\ImageProcessor, which re-encodes and strips EXIF whatever arrives), so they go at 1600 px, JPEG 0.82 (RECEIPT_PHOTO).
// - Product photos (step 6.4b, phase-6 plan 6.4b): the admin frames them first, and the server makes a large rendition of up to 1600 x 1200
//   from the crop, so they go at 2400 px, JPEG 0.9 (PRODUCT_PHOTO): a crop zoomed to 1.33x of such a file is still 1600 px wide.
//
// The picture is decoded with its EXIF orientation applied, drawn on a canvas no larger than the preset's long side (never enlarged), on
// white (a PNG's transparency would turn black in a JPEG), and encoded as JPEG. The canvas writes no metadata, so the GPS position of the
// photo does not even leave the device, and the file sent has the orientation the framing step saw. When the browser cannot decode the
// file (an unknown format, an old browser without createImageBitmap), the original file is kept as it is and the server decides.

export interface DownscalePreset {
  /** the longest side, in pixels */
  maxEdge: number;
  /** the JPEG quality, 0 to 1 */
  quality: number;
}

export const RECEIPT_PHOTO: Readonly<DownscalePreset> = { maxEdge: 1600, quality: 0.82 };
export const PRODUCT_PHOTO: Readonly<DownscalePreset> = { maxEdge: 2400, quality: 0.9 };

/** The receipt preset's numbers, under their Phase 4 names. */
export const MAX_EDGE = RECEIPT_PHOTO.maxEdge;
export const QUALITY = RECEIPT_PHOTO.quality;

/** The size that fits within `max` on the longest side, aspect kept, never larger than the original; at least 1 px each way. */
export function fitWithin(width: number, height: number, max = MAX_EDGE): { width: number; height: number } {
  const longest = Math.max(width, height);
  if (longest <= max) return { width, height };
  const scale = max / longest;
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

async function encode(bitmap: ImageBitmap, width: number, height: number, quality: number): Promise<Blob | null> {
  if (typeof OffscreenCanvas === "function") {
    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext("2d");
    if (!context) return null;
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, width, height);
    context.drawImage(bitmap, 0, 0, width, height);
    return canvas.convertToBlob({ type: "image/jpeg", quality });
  }
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d");
  if (!context) return null;
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, width, height);
  context.drawImage(bitmap, 0, 0, width, height);
  return new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));
}

/** The photo shrunk with `preset`, with the size of what is sent; null when the browser cannot decode it. */
export async function downscaleSized(file: Blob, preset: DownscalePreset = RECEIPT_PHOTO): Promise<{ blob: Blob; width: number; height: number } | null> {
  if (typeof createImageBitmap !== "function") return null;
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
  } catch {
    return null;
  }
  try {
    const size = fitWithin(bitmap.width, bitmap.height, preset.maxEdge);
    const blob = await encode(bitmap, size.width, size.height, preset.quality);
    return blob && blob.size > 0 ? { blob, ...size } : null;
  } catch {
    return null;
  } finally {
    bitmap.close();
  }
}

/** The photo shrunk with `preset` (a receipt's by default), or the original file when the browser cannot decode it. */
export async function downscale(file: Blob, preset: DownscalePreset = RECEIPT_PHOTO): Promise<Blob> {
  return (await downscaleSized(file, preset))?.blob ?? file;
}
