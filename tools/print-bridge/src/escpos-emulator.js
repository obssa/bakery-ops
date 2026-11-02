// A receipt printer in a test (step 5.3b): apps/api/tests/Support/EscPosEmulator.php ported rule for rule. It reads an ESC/POS byte
// stream the way a printer would and keeps what it understood, so a test checks what would come out of the printer, not the encoder's
// own idea of it. It knows exactly the commands the server's encoder may send and refuses anything else (a stray byte would print
// garbage or jam a real printer):
//
//   ESC @          init                                   GS v 0 m xL xH yL yH d...   raster (m 0: normal size; x bytes a row, y rows)
//   ESC d n        feed n lines                           GS V m (n for m 65 or 66)   cut (0/48 full, 1/49 partial; 65/66 feed n first)
//   ESC a n        align (0 left, 1 centre, 2 right)      GS ! n                      character size (0 normal, 0x11 double)
//   ESC E n        bold off/on                            LF                          print the text line and feed one line
//   ESC t n        code page                              0x20-0x7E                   a printable ASCII character
//
// bitmaps() joins the raster bands of each copy (a copy ends at a cut) into one picture; lines() is the text the printer's own font
// printed (the server's fallback without FreeType). Step 5.3c's Playwright spec imports this file to read what its fake bridge printed;
// keep it free of Node-only modules.

export class EscPosError extends Error {
  constructor(message) {
    super(message);
    this.name = 'EscPosError';
  }
}

const hex = (n) => `0x${n.toString(16).toUpperCase().padStart(2, '0')}`;

/** A 1-bit picture: width dots (a whole number of bytes), height rows, each row width / 8 bytes, the leftmost dot in the high bit. */
export class Bitmap {
  constructor(width, height, data) {
    if (!(width > 0) || width % 8 !== 0 || height < 0 || data.length !== (width / 8) * height) {
      throw new EscPosError(`A ${width}x${height} picture is a whole number of bytes wide and has ${(width / 8) * height} bytes; got ${data.length}.`);
    }
    this.width = width;
    this.height = height;
    this.data = data;
  }

  bytesPerRow() {
    return this.width / 8;
  }

  isBlack(x, y) {
    return (this.data[y * this.bytesPerRow() + (x >> 3)] & (0x80 >> (x & 7))) !== 0;
  }

  /** How many dots are black. */
  blackDots() {
    let count = 0;
    for (let b of this.data) {
      while (b) {
        count += b & 1;
        b >>= 1;
      }
    }
    return count;
  }
}

function toBytes(input) {
  if (input instanceof Uint8Array) return input;
  if (input instanceof ArrayBuffer) return new Uint8Array(input);
  if (ArrayBuffer.isView(input)) return new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
  if (typeof input === 'string') return Uint8Array.from(input, (c) => c.charCodeAt(0) & 0xff); // a binary string, as PHP has it
  throw new TypeError('An ESC/POS stream is a Uint8Array, a Buffer, an ArrayBuffer or a binary string.');
}

export class EscPosEmulator {
  constructor() {
    /** @type {Array<Record<string, number|string>>} */
    this.commands = [];
    this._bands = [];
    this._bitmaps = [];
    this._lines = [];
    this._text = '';
    this._uncut = false; // whether something was printed since the last cut
  }

  static parse(bytes) {
    const printer = new EscPosEmulator();
    printer._read(toBytes(bytes));
    return printer;
  }

  /** The command names in order: init, raster, feed, cut, align, bold, size, codepage, text, lf. */
  names() {
    return this.commands.map((c) => String(c.cmd));
  }

  /** One picture per copy: the raster bands printed before each cut, stacked. */
  bitmaps() {
    return this._bitmaps;
  }

  /** The text lines printed in the printer's own font. */
  lines() {
    return this._lines;
  }

  _read(bytes) {
    const length = bytes.length;
    let i = 0;
    const need = (count) => {
      if (i + count > length) throw new EscPosError(`ESC/POS stream ends inside a command at byte ${i}.`);
    };
    while (i < length) {
      const byte = bytes[i];
      if (byte === 0x1b) {
        need(2);
        const op = String.fromCharCode(bytes[i + 1]);
        if (op === '@') {
          this.commands.push({ cmd: 'init' });
          i += 2;
          continue;
        }
        need(3);
        const n = bytes[i + 2];
        const known = { d: ['feed', 'lines'], a: ['align', 'n'], E: ['bold', 'n'], t: ['codepage', 'n'] }[op];
        if (!known) throw new EscPosError(`Unknown ESC command ${hex(bytes[i + 1])} at byte ${i}.`);
        this.commands.push({ cmd: known[0], [known[1]]: n });
        i += 3;
        continue;
      }
      if (byte === 0x1d) {
        need(2);
        const op = String.fromCharCode(bytes[i + 1]);
        if (op === 'v') {
          need(8);
          if (bytes[i + 2] !== 0x30) throw new EscPosError(`Unknown GS v function at byte ${i}.`);
          const mode = bytes[i + 3];
          const xBytes = bytes[i + 4] | (bytes[i + 5] << 8);
          const rows = bytes[i + 6] | (bytes[i + 7] << 8);
          const size = xBytes * rows;
          need(8 + size);
          this.commands.push({ cmd: 'raster', mode, bytes: xBytes, rows });
          this._bands.push({ rows, bytes: xBytes, data: bytes.subarray(i + 8, i + 8 + size) });
          this._uncut = true;
          i += 8 + size;
          continue;
        }
        if (op === 'V') {
          need(3);
          const mode = bytes[i + 2];
          if (mode === 65 || mode === 66) {
            need(4);
            this.commands.push({ cmd: 'cut', mode, feed: bytes[i + 3] });
            i += 4;
          } else if ([0, 1, 48, 49].includes(mode)) {
            this.commands.push({ cmd: 'cut', mode });
            i += 3;
          } else {
            throw new EscPosError(`Unknown GS V mode ${mode} at byte ${i}.`);
          }
          this._endCopy();
          continue;
        }
        if (op === '!') {
          need(3);
          this.commands.push({ cmd: 'size', n: bytes[i + 2] });
          i += 3;
          continue;
        }
        throw new EscPosError(`Unknown GS command ${hex(bytes[i + 1])} at byte ${i}.`);
      }
      if (byte === 0x0a) {
        this.commands.push({ cmd: 'lf' });
        this._lines.push(this._text);
        this._text = '';
        this._uncut = true;
        i++;
        continue;
      }
      if (byte >= 0x20 && byte <= 0x7e) {
        if (this.commands.at(-1)?.cmd !== 'text') this.commands.push({ cmd: 'text' });
        this._text += String.fromCharCode(byte);
        i++;
        continue;
      }
      throw new EscPosError(`Unexpected byte ${hex(byte)} at byte ${i}.`);
    }
    if (this._uncut || this._text !== '') {
      throw new EscPosError('The stream ends without a cut: the last receipt would stay in the printer.');
    }
  }

  _endCopy() {
    this._uncut = false;
    if (this._bands.length === 0) return;
    if (new Set(this._bands.map((b) => b.bytes)).size !== 1) {
      throw new EscPosError('The raster bands of one receipt have different widths.');
    }
    const height = this._bands.reduce((sum, b) => sum + b.rows, 0);
    const data = new Uint8Array(this._bands.reduce((sum, b) => sum + b.data.length, 0));
    let at = 0;
    for (const b of this._bands) {
      data.set(b.data, at);
      at += b.data.length;
    }
    this._bitmaps.push(new Bitmap(this._bands[0].bytes * 8, height, data));
    this._bands = [];
  }
}

/** EscPosEmulator.parse(bytes). */
export function parse(bytes) {
  return EscPosEmulator.parse(bytes);
}
