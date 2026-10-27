// Receipt photos are shrunk in the browser before they are uploaded (step 4.5b, docs/phases/phase-4-plan.md section 6 item 5): a phone's
// 12 MP photo is several megabytes on a slow line, and the server keeps a longest side of 1600 px anyway (Shared\Files\ImageProcessor,
// which re-encodes and strips EXIF whatever arrives). The picture is decoded with its EXIF orientation applied, drawn on a canvas no larger
// than 1600 px on its longest side (never enlarged), on white (a PNG's transparency would turn black in a JPEG), and encoded as JPEG 0.82.
// The canvas writes no metadata, so the GPS position of the photo does not even leave the device. When the browser cannot decode the
// file (an unknown format, an old browser without createImageBitmap), the original file is sent as it is and the server decides.

export const MAX_EDGE = 1600;
export const QUALITY = 0.82;

/** The size that fits within `max` on the longest side, aspect kept, never larger than the original; at least 1 px each way. */
export function fitWithin(width: number, height: number, max = MAX_EDGE): { width: number; height: number } {
  const longest = Math.max(width, height);
  if (longest <= max) return { width, height };
  const scale = max / longest;
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

async function encode(bitmap: ImageBitmap, width: number, height: number): Promise<Blob | null> {
  if (typeof OffscreenCanvas === "function") {
    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext("2d");
    if (!context) return null;
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, width, height);
    context.drawImage(bitmap, 0, 0, width, height);
    return canvas.convertToBlob({ type: "image/jpeg", quality: QUALITY });
  }
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d");
  if (!context) return null;
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, width, height);
  context.drawImage(bitmap, 0, 0, width, height);
  return new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", QUALITY));
}

/** The photo shrunk to a JPEG of at most 1600 px on its longest side, or the original file when the browser cannot decode it. */
export async function downscale(file: Blob): Promise<Blob> {
  if (typeof createImageBitmap !== "function") return file;
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
  } catch {
    return file;
  }
  try {
    const size = fitWithin(bitmap.width, bitmap.height);
    const blob = await encode(bitmap, size.width, size.height);
    return blob && blob.size > 0 ? blob : file;
  } catch {
    return file;
  } finally {
    bitmap.close();
  }
}
