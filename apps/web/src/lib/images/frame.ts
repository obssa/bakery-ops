// The framing step of the product page's Photo card (step 6.4b; docs/design/fresh-counter.md 4.3; the ProductPhoto board), as plain
// functions the logic spec calls without a browser (e2e/p6/product-photo-logic.spec.ts).
//
// The photo sits inside a fixed 4:3 frame. At 1x the frame shows the largest 4:3 rectangle of the photo, centred (the photo "covers" the
// frame); at zoom z it shows that rectangle shrunk z times, anywhere inside the photo. The view is the zoom and the centre of the shown
// rectangle in the photo's own pixels: the pixels of the file the browser uploads, which is the picture shrunk to 2400 px on its long
// side with its EXIF orientation applied (lib/images/downscale.ts PRODUCT_PHOTO), so the server's crop (POST /items/{item}/image,
// step 6.1a) reads the same numbers. What is sent is `sourceRect`: whole pixels, exactly 4:3 (4k x 3k) and inside the photo, so the
// server's snap to 4:3 keeps it as it is. The quality line reads that rectangle: sharp from 1200 x 900, soft from the server's minimum of
// 800 x 600, too small below it (the card then keeps "Use this photo" off).

export interface Size {
  width: number;
  height: number;
}

/** What the frame shows: the zoom (1 to 3) and the centre of the shown rectangle, in the photo's pixels. */
export interface FrameView {
  zoom: number;
  cx: number;
  cy: number;
}

/** A rectangle of the photo, in its pixels. */
export interface CropRect extends Size {
  x: number;
  y: number;
}

/** A point of the frame as fractions of its width and height (0, 0 is the top left). */
export interface FramePoint {
  x: number;
  y: number;
}

export type PhotoQuality = "sharp" | "soft" | "too_small";

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 3;

/** The server's smallest crop (catalog.images.min_width and min_height, step 6.1a): under it the upload is refused, too_small. */
export const MIN_CROP: Readonly<Size> = { width: 800, height: 600 };
/** From this crop up the photo is sharp on every screen (the large rendition is 1600 x 1200, shown at up to 480 px on a 2x screen). */
export const SHARP_CROP: Readonly<Size> = { width: 1200, height: 900 };

// a rectangle computed in floating point that is 4:3 "exactly" may come out a hair under a whole 4k x 3k
const EPSILON = 1e-6;

function clamp(value: number, low: number, high: number): number {
  return Math.min(Math.max(value, low), high);
}

/** The zoom held between 1x and 3x (1x when it is not a number). */
export function clampZoom(zoom: number): number {
  return Number.isFinite(zoom) ? clamp(zoom, MIN_ZOOM, MAX_ZOOM) : MIN_ZOOM;
}

/** The largest 4:3 rectangle inside the photo: what the frame shows at 1x. */
export function coverSize(source: Size): Size {
  return source.width * 3 > source.height * 4
    ? { width: (source.height * 4) / 3, height: source.height }
    : { width: source.width, height: (source.width * 3) / 4 };
}

function shownSize(source: Size, zoom: number): Size {
  const cover = coverSize(source);
  const z = clampZoom(zoom);
  return { width: cover.width / z, height: cover.height / z };
}

/** The view held inside the photo: the zoom between 1x and 3x, and the centre where the shown rectangle stays on the photo. */
export function clampView(source: Size, view: FrameView): FrameView {
  const zoom = clampZoom(view.zoom);
  const shown = shownSize(source, zoom);
  const half = { width: shown.width / 2, height: shown.height / 2 };
  const cx = half.width * 2 >= source.width ? source.width / 2 : clamp(Number.isFinite(view.cx) ? view.cx : source.width / 2, half.width, source.width - half.width);
  const cy = half.height * 2 >= source.height ? source.height / 2 : clamp(Number.isFinite(view.cy) ? view.cy : source.height / 2, half.height, source.height - half.height);
  return { zoom, cx, cy };
}

/** The first view of a photo: 1x, centred. */
export function initialView(source: Size): FrameView {
  return { zoom: MIN_ZOOM, cx: source.width / 2, cy: source.height / 2 };
}

/** The rectangle the frame shows, in the photo's pixels (not rounded). */
export function cropOf(source: Size, view: FrameView): CropRect {
  const held = clampView(source, view);
  const shown = shownSize(source, held.zoom);
  return { x: held.cx - shown.width / 2, y: held.cy - shown.height / 2, width: shown.width, height: shown.height };
}

/**
 * The view after the pointer moved by (dx, dy) screen pixels over a frame drawn `frameWidth` pixels wide: the photo follows the pointer, so
 * the shown rectangle moves the other way, by as many of the photo's pixels as a screen pixel covers. Held inside the photo.
 */
export function panBy(source: Size, view: FrameView, frameWidth: number, dx: number, dy: number): FrameView {
  const held = clampView(source, view);
  const perPixel = frameWidth > 0 ? shownSize(source, held.zoom).width / frameWidth : 0;
  return clampView(source, { zoom: held.zoom, cx: held.cx - dx * perPixel, cy: held.cy - dy * perPixel });
}

/**
 * The view at another zoom, keeping the point of the photo under `anchor` (a point of the frame; its centre by default, as the slider
 * zooms) where it was: a pinch zooms where the fingers are. Held inside the photo.
 */
export function zoomAt(source: Size, view: FrameView, zoom: number, anchor: FramePoint = { x: 0.5, y: 0.5 }): FrameView {
  const before = cropOf(source, view);
  const point = { x: before.x + anchor.x * before.width, y: before.y + anchor.y * before.height };
  const next = clampZoom(zoom);
  const shown = shownSize(source, next);
  return clampView(source, { zoom: next, cx: point.x - anchor.x * shown.width + shown.width / 2, cy: point.y - anchor.y * shown.height + shown.height / 2 });
}

/**
 * What is sent: the shown rectangle in whole pixels, exactly 4:3 (4k x 3k, the largest that fits in it), around the same centre and inside
 * the photo. A photo too small for even 4 x 3 pixels gives an empty rectangle.
 */
export function sourceRect(source: Size, view: FrameView): CropRect {
  const crop = cropOf(source, view);
  const k = Math.max(0, Math.floor(Math.min(crop.width / 4, crop.height / 3) + EPSILON));
  const width = Math.min(4 * k, Math.floor(source.width));
  const height = Math.min(3 * k, Math.floor(source.height));
  const centre = { x: crop.x + crop.width / 2, y: crop.y + crop.height / 2 };
  return {
    x: clamp(Math.round(centre.x - width / 2), 0, Math.max(0, Math.floor(source.width) - width)),
    y: clamp(Math.round(centre.y - height / 2), 0, Math.max(0, Math.floor(source.height) - height)),
    width,
    height,
  };
}

/** The quality line's tier for a crop: sharp from 1200 x 900, soft from 800 x 600 (the server's minimum), too small below it. */
export function qualityOf(crop: Size): PhotoQuality {
  if (crop.width >= SHARP_CROP.width && crop.height >= SHARP_CROP.height) return "sharp";
  if (crop.width >= MIN_CROP.width && crop.height >= MIN_CROP.height) return "soft";
  return "too_small";
}

/**
 * Where the img is drawn inside the frame, in percent of the frame (the ProductPhoto board's `left: -12%; top: -14%; width: 124%`): the
 * whole photo, scaled so the shown rectangle fills the frame and moved so it is the part in view. The same numbers draw the live preview
 * at any size (the pad card, the order line's thumb).
 */
export function imageStyle(source: Size, view: FrameView): { left: number; top: number; width: number; height: number } {
  const crop = cropOf(source, view);
  return {
    // 0 - x, not -x: a photo at its top left is at 0, never -0
    left: 0 - (crop.x / crop.width) * 100,
    top: 0 - (crop.y / crop.height) * 100,
    width: (source.width / crop.width) * 100,
    height: (source.height / crop.height) * 100,
  };
}

/** The four crop fields of POST /items/{item}/image (multipart, so text). */
export function cropFields(rect: CropRect): { crop_x: string; crop_y: string; crop_width: string; crop_height: string } {
  return { crop_x: String(rect.x), crop_y: String(rect.y), crop_width: String(rect.width), crop_height: String(rect.height) };
}
