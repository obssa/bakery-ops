import type { Page } from "@playwright/test";

// Receipt photos for the p4 pay flows (step 4.5b, docs/phases/phase-4-plan.md section 7): no binary fixture is committed. The browser's
// canvas makes a JPEG, and a hand-built EXIF block (orientation and a GPS IFD holding GPSLatitudeRef "N", the twin of the API tests'
// imgExifApp1 in apps/api/tests/Support/images.php) is spliced in right after SOI, so the flows can prove that what the server stored has
// no EXIF and a longest side of 1600 px. The readers below parse the JPEG's own segments; they share no code with the app.

/**
 * A JPEG of width x height drawn by the page's canvas: blue (or the given background), with a red square in the top-left corner. Every
 * stored photo of the same drawing has the same bytes, which the API flags as a duplicate receipt; a flow that must not meet an earlier
 * run's photo passes a background of its own (runTint).
 */
export async function canvasJpeg(page: Page, width: number, height: number, quality = 0.9, background = "rgb(20, 20, 220)"): Promise<Buffer> {
  const dataUrl = await page.evaluate(
    ([w, h, q, fill]) => {
      const canvas = document.createElement("canvas");
      canvas.width = w;
      canvas.height = h;
      const context = canvas.getContext("2d");
      if (!context) throw new Error("no 2d canvas");
      context.fillStyle = fill;
      context.fillRect(0, 0, w, h);
      context.fillStyle = "rgb(230, 20, 20)";
      const square = Math.max(2, Math.floor(Math.min(w, h) / 3));
      context.fillRect(0, 0, square, square);
      return canvas.toDataURL("image/jpeg", q);
    },
    [width, height, quality, background] as const,
  );
  return Buffer.from(dataUrl.slice(dataUrl.indexOf(",") + 1), "base64");
}

function u16(value: number): Buffer {
  const b = Buffer.alloc(2);
  b.writeUInt16BE(value);
  return b;
}

function u32(value: number): Buffer {
  const b = Buffer.alloc(4);
  b.writeUInt32BE(value);
  return b;
}

/** An EXIF APP1 segment (marker, length, "Exif", big-endian TIFF) with Orientation and a GPS IFD holding GPSLatitudeRef "N". */
export function exifApp1(orientation = 1): Buffer {
  const gpsOffset = 8 + 2 + 12 * 2 + 4;
  const tiff = Buffer.concat([
    Buffer.from("MM\x00\x2A", "latin1"),
    u32(8),
    u16(2),
    // Orientation (SHORT, 1 value)
    u16(0x0112),
    u16(3),
    u32(1),
    u16(orientation),
    Buffer.from([0, 0]),
    // GPSInfo IFD pointer (LONG)
    u16(0x8825),
    u16(4),
    u32(1),
    u32(gpsOffset),
    u32(0), // no next IFD
    // the GPS IFD: GPSLatitudeRef (ASCII, 2) = "N"
    u16(1),
    u16(1),
    u16(2),
    u32(2),
    Buffer.from("N\x00\x00\x00", "latin1"),
    u32(0),
  ]);
  const exif = Buffer.concat([Buffer.from("Exif\x00\x00", "latin1"), tiff]);
  return Buffer.concat([Buffer.from([0xff, 0xe1]), u16(exif.length + 2), exif]);
}

/** The JPEG with extra segments spliced in right after SOI. */
export function injectSegments(jpeg: Buffer, ...segments: Buffer[]): Buffer {
  return Buffer.concat([jpeg.subarray(0, 2), ...segments, jpeg.subarray(2)]);
}

/** Every segment of the JPEG up to the start of scan: its marker byte and its payload. */
export function segmentsOf(bytes: Buffer): { marker: number; payload: Buffer }[] {
  if (bytes[0] !== 0xff || bytes[1] !== 0xd8) throw new Error("not a JPEG (no SOI)");
  const out: { marker: number; payload: Buffer }[] = [];
  let i = 2;
  while (i + 4 <= bytes.length && bytes[i] === 0xff) {
    const marker = bytes[i + 1];
    const length = bytes.readUInt16BE(i + 2);
    out.push({ marker, payload: bytes.subarray(i + 4, i + 2 + length) });
    if (marker === 0xda) break;
    i += 2 + length;
  }
  return out;
}

/** Does the JPEG carry an EXIF block (an APP1 segment that starts with "Exif")? */
export function hasExif(bytes: Buffer): boolean {
  return segmentsOf(bytes).some((s) => s.marker === 0xe1 && s.payload.subarray(0, 4).toString("latin1") === "Exif");
}

/** The JPEG's frame size, from its SOFn segment. */
export function jpegSize(bytes: Buffer): { width: number; height: number } {
  const frame = segmentsOf(bytes).find((s) => s.marker >= 0xc0 && s.marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(s.marker));
  if (!frame) throw new Error("no SOF segment");
  return { height: frame.payload.readUInt16BE(1), width: frame.payload.readUInt16BE(3) };
}

/** A background colour of this text's own (the run id): two runs' photos are not the same picture. */
export function runTint(text: string): string {
  let hash = 2166136261;
  for (const char of text) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619) >>> 0;
  return `rgb(${hash & 0xff}, ${(hash >>> 8) & 0xff}, ${(hash >>> 16) & 0xff})`;
}

/** The photo of a receipt as a phone would take it: 2400 x 1800 with a GPS position in its EXIF. */
export async function receiptPhoto(page: Page): Promise<{ name: string; mimeType: string; buffer: Buffer }> {
  const jpeg = injectSegments(await canvasJpeg(page, 2400, 1800), exifApp1(1));
  if (!hasExif(jpeg) || jpegSize(jpeg).width !== 2400) throw new Error("the receipt photo fixture is not what it should be");
  return { name: "receipt.jpg", mimeType: "image/jpeg", buffer: jpeg };
}

/** The bytes behind a URL the API signed (a receipt photo); fetched like an <img> would, with no bearer token. */
export async function fetchBytes(url: string): Promise<{ status: number; type: string | null; bytes: Buffer }> {
  const response = await fetch(url);
  return { status: response.status, type: response.headers.get("content-type"), bytes: Buffer.from(await response.arrayBuffer()) };
}

/**
 * Records what the page uploads as a file (POST /files, multipart): the browser does not expose a multipart body that holds a Blob to the
 * test (request.postDataBuffer() is null), so a wrapper around the page's fetch keeps a copy of each `file` part, base64, in the page.
 * It observes only; the request goes out unchanged. Install it before the page loads.
 */
export async function recordUploads(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const seen: { type: string; size: number; base64: string }[] = [];
    (window as unknown as { __e2eUploads: typeof seen }).__e2eUploads = seen;
    const original = window.fetch.bind(window);
    window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
      const body = init?.body;
      if (body instanceof FormData) {
        const file = body.get("file");
        if (file instanceof Blob) {
          const bytes = new Uint8Array(await file.arrayBuffer());
          let binary = "";
          for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
          seen.push({ type: file.type, size: file.size, base64: btoa(binary) });
        }
      }
      return original(input, init);
    };
  });
}

/** The files the page uploaded so far (recordUploads), oldest first. */
export async function uploadsOf(page: Page): Promise<{ type: string; bytes: Buffer }[]> {
  const seen = await page.evaluate(() => (window as unknown as { __e2eUploads?: { type: string; base64: string }[] }).__e2eUploads ?? []);
  return seen.map((u) => ({ type: u.type, bytes: Buffer.from(u.base64, "base64") }));
}
