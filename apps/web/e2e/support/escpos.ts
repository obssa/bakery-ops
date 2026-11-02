// The framing of a receipt's ESC/POS bytes, as the fake bridge of e2e/p5/print.spec.ts reads them (step 5.3c). The API draws the receipt
// as a raster (Payments EscPos, step 5.3a): per copy `ESC @`, the receipt in `GS v 0` bands, a feed (`ESC d n`) and the `GS V` cut.
//
// A stand-in until 5.3b's JS emulator (tools/print-bridge/src/escpos-emulator.js) is on main: this reads the commands and checks their
// order, not the bitmap. Once the emulator lands, the spec decodes with it instead.

export type EscPosCommand =
  | { op: "init" }
  | { op: "raster"; mode: number; widthBytes: number; rows: number }
  | { op: "feed"; lines: number }
  | { op: "cut"; mode: number }
  | { op: "text"; bytes: number }
  | { op: "other"; code: string };

const ESC = 0x1b;
const GS = 0x1d;
/** ESC and GS commands with one parameter byte the receipt's text fallback may use: align, bold, print mode, code page, character size */
const ONE_BYTE: Record<string, true> = { "1b61": true, "1b45": true, "1b21": true, "1b74": true, "1d21": true };

/** The bytes as a list of commands; text runs are merged. Throws on a raster band cut short. */
export function readEscPos(bytes: Uint8Array): EscPosCommand[] {
  const out: EscPosCommand[] = [];
  const text = (n: number) => {
    const last = out[out.length - 1];
    if (last && last.op === "text") last.bytes += n;
    else out.push({ op: "text", bytes: n });
  };
  let i = 0;
  while (i < bytes.length) {
    const b = bytes[i];
    const next = bytes[i + 1];
    if (b === ESC && next === 0x40) {
      out.push({ op: "init" });
      i += 2;
    } else if (b === GS && next === 0x76 && bytes[i + 2] === 0x30) {
      if (i + 8 > bytes.length) throw new Error(`a GS v 0 header cut short at byte ${i}`);
      const mode = bytes[i + 3];
      const widthBytes = bytes[i + 4] + bytes[i + 5] * 256;
      const rows = bytes[i + 6] + bytes[i + 7] * 256;
      const end = i + 8 + widthBytes * rows;
      if (end > bytes.length) throw new Error(`a GS v 0 band of ${widthBytes} x ${rows} cut short at byte ${i}`);
      out.push({ op: "raster", mode, widthBytes, rows });
      i = end;
    } else if (b === ESC && next === 0x64) {
      out.push({ op: "feed", lines: bytes[i + 2] ?? 0 });
      i += 3;
    } else if (b === GS && next === 0x56) {
      const mode = bytes[i + 2] ?? 0;
      out.push({ op: "cut", mode });
      i += mode === 65 || mode === 66 ? 4 : 3;
    } else if ((b === ESC || b === GS) && next !== undefined && ONE_BYTE[`${b.toString(16)}${next.toString(16).padStart(2, "0")}`]) {
      out.push({ op: "other", code: `${b.toString(16)}${next.toString(16).padStart(2, "0")}` });
      i += 3;
    } else {
      text(1);
      i += 1;
    }
  }
  return out;
}

export interface ReceiptFrame {
  copies: number;
  /** the raster's width in dots: 576 for 80 mm paper, 384 for 58 mm */
  widthDots: number;
  /** the raster's height in dots, per copy */
  rows: number;
  bands: number;
}

/**
 * Checks a receipt's framing and says what it holds: every copy starts with `ESC @`, draws in `GS v 0` bands of one width and ends with the
 * `GS V` cut; nothing follows the last cut. Throws, saying what is wrong, otherwise.
 */
export function checkReceiptFrame(bytes: Uint8Array): ReceiptFrame {
  const commands = readEscPos(bytes);
  if (commands.length === 0) throw new Error("no bytes");
  if (commands[0].op !== "init") throw new Error(`the receipt starts with ${commands[0].op}, not ESC @`);
  if (commands[commands.length - 1].op !== "cut") throw new Error(`the receipt ends with ${commands[commands.length - 1].op}, not the GS V cut`);

  const copies: EscPosCommand[][] = [];
  for (const command of commands) {
    if (command.op === "init") copies.push([]);
    copies[copies.length - 1].push(command);
  }
  let widthBytes: number | null = null;
  let rows = 0;
  let bands = 0;
  copies.forEach((copy, n) => {
    const raster = copy.filter((c): c is Extract<EscPosCommand, { op: "raster" }> => c.op === "raster");
    if (raster.length === 0) throw new Error(`copy ${n + 1} has no GS v 0 raster`);
    if (copy[copy.length - 1].op !== "cut") throw new Error(`copy ${n + 1} does not end with the GS V cut`);
    if (copy.filter((c) => c.op === "cut").length !== 1) throw new Error(`copy ${n + 1} is cut more than once`);
    const lastBand = copy.lastIndexOf(raster[raster.length - 1]);
    const cutAt = copy.length - 1;
    if (copy.slice(lastBand + 1, cutAt).some((c) => c.op === "raster")) throw new Error(`copy ${n + 1} draws after its cut`);
    for (const band of raster) {
      if (widthBytes === null) widthBytes = band.widthBytes;
      if (band.widthBytes !== widthBytes) throw new Error(`bands of ${widthBytes} and ${band.widthBytes} bytes`);
    }
    if (n === 0) {
      rows = raster.reduce((sum, band) => sum + band.rows, 0);
      bands = raster.length;
    }
  });
  return { copies: copies.length, widthDots: (widthBytes ?? 0) * 8, rows, bands };
}
