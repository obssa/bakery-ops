// A JS copy of the server's encoder (apps/api/app/Modules/Payments/Support/Printing/EscPos.php) for the tests: the bytes a claim's
// escpos_base64 holds. ESC @ once, then per copy the picture in GS v 0 bands of at most 960 rows, ESC d 4 and GS V 1 (a partial cut).

export const BAND_ROWS = 960;

/** A 1-bit picture: width dots (a multiple of 8), height rows, each row width / 8 bytes, the leftmost dot in the high bit. */
export function picture(width, height, black = (x, y) => (x + y) % 7 === 0) {
  const bytes = width / 8;
  const data = new Uint8Array(bytes * height);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (black(x, y)) data[y * bytes + (x >> 3)] |= 0x80 >> (x & 7);
    }
  }
  return { width, height, data };
}

export function raster(pic, copies = 1) {
  const bytes = pic.width / 8;
  const parts = [];
  for (let from = 0; from < pic.height; from += BAND_ROWS) {
    const rows = Math.min(BAND_ROWS, pic.height - from);
    parts.push(Uint8Array.of(0x1d, 0x76, 0x30, 0x00, bytes & 0xff, bytes >> 8, rows & 0xff, rows >> 8));
    parts.push(pic.data.subarray(from * bytes, (from + rows) * bytes));
  }
  parts.push(Uint8Array.of(0x1b, 0x64, 4, 0x1d, 0x56, 0x01));
  const copy = Buffer.concat(parts);
  return Buffer.concat([Buffer.from([0x1b, 0x40]), ...Array.from({ length: Math.max(1, copies) }, () => copy)]);
}

/** The printer's own font: lines of {text, align?, bold?} (the server's fallback without FreeType). */
export function text(lines, copies = 1) {
  const parts = [];
  for (const line of lines) {
    parts.push(Uint8Array.of(0x1b, 0x61, line.align === 'center' ? 1 : 0, 0x1b, 0x45, line.bold ? 1 : 0));
    parts.push(Buffer.from(line.text.replace(/[^\x20-\x7e]/gu, '?'), 'latin1'));
    parts.push(Uint8Array.of(0x0a));
  }
  parts.push(Uint8Array.of(0x1b, 0x64, 4, 0x1d, 0x56, 0x01));
  const copy = Buffer.concat(parts);
  return Buffer.concat([Buffer.from([0x1b, 0x40, 0x1b, 0x74, 0x00]), ...Array.from({ length: Math.max(1, copies) }, () => copy)]);
}

/** A receipt the size of a real one: 576 dots wide, 1 500 rows (two bands). */
export function receipt({ width = 576, rows = 1500, copies = 1 } = {}) {
  return raster(picture(width, rows), copies);
}
