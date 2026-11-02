// The JS emulator is the PHP one (apps/api/tests/Support/EscPosEmulator.php) ported rule for rule: the same commands, the same
// refusals, the same pictures. Step 5.3c's Playwright spec imports it to read what its fake bridge printed.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { Bitmap, EscPosEmulator, EscPosError, parse } from '../src/escpos-emulator.js';
import { picture, raster, receipt, text } from './support/escpos.js';

const bytes = (...xs) => Uint8Array.from(xs.flat());
const CUT = [0x1d, 0x56, 0x01];

describe('EscPosEmulator', () => {
  it('names every command it knows, in order', () => {
    const p = EscPosEmulator.parse(
      bytes([0x1b, 0x40], [0x1b, 0x74, 0], [0x1b, 0x61, 1], [0x1b, 0x45, 1], [0x1d, 0x21, 0x11], [0x41, 0x42], [0x0a], [0x1b, 0x64, 4], CUT),
    );
    assert.deepEqual(p.names(), ['init', 'codepage', 'align', 'bold', 'size', 'text', 'lf', 'feed', 'cut']);
    assert.deepEqual(p.commands[2], { cmd: 'align', n: 1 });
    assert.deepEqual(p.commands[7], { cmd: 'feed', lines: 4 });
    assert.deepEqual(p.commands[8], { cmd: 'cut', mode: 1 });
  });

  it('joins the raster bands of one copy into one picture, one picture per copy', () => {
    const pic = picture(576, 1500);
    const p = parse(raster(pic, 2));
    assert.deepEqual(p.names(), ['init', 'raster', 'raster', 'feed', 'cut', 'raster', 'raster', 'feed', 'cut']);
    assert.deepEqual(p.commands[1], { cmd: 'raster', mode: 0, bytes: 72, rows: 960 });
    assert.deepEqual(p.commands[2], { cmd: 'raster', mode: 0, bytes: 72, rows: 540 });
    const [first, second] = p.bitmaps();
    assert.equal(p.bitmaps().length, 2);
    assert.ok(first instanceof Bitmap);
    assert.equal(first.width, 576);
    assert.equal(first.height, 1500);
    assert.deepEqual(Buffer.from(first.data), Buffer.from(pic.data));
    assert.deepEqual(Buffer.from(second.data), Buffer.from(pic.data));
  });

  it('reads a 58 mm receipt (384 dots)', () => {
    const [bmp] = parse(receipt({ width: 384, rows: 200 })).bitmaps();
    assert.equal(bmp.width, 384);
    assert.equal(bmp.bytesPerRow(), 48);
    assert.equal(bmp.height, 200);
  });

  it('knows which dots are black', () => {
    const [bmp] = parse(raster(picture(16, 2, (x, y) => (x === 0 && y === 0) || (x === 15 && y === 1)))).bitmaps();
    assert.equal(bmp.isBlack(0, 0), true);
    assert.equal(bmp.isBlack(1, 0), false);
    assert.equal(bmp.isBlack(15, 1), true);
    assert.equal(bmp.blackDots(), 2);
  });

  it("keeps the text the printer's own font printed", () => {
    const p = parse(text([{ text: 'BAKERY', align: 'center', bold: true }, { text: 'Total 120.00' }]));
    assert.deepEqual(p.lines(), ['BAKERY', 'Total 120.00']);
    assert.equal(p.bitmaps().length, 0);
    assert.equal(p.names().filter((n) => n === 'text').length, 2);
  });

  it('takes a Buffer, a Uint8Array, an ArrayBuffer or a binary string', () => {
    const b = receipt({ rows: 10 });
    for (const input of [b, new Uint8Array(b), b.buffer.slice(b.byteOffset, b.byteOffset + b.length), b.toString('latin1')]) {
      assert.equal(parse(input).bitmaps()[0].height, 10);
    }
  });

  it('takes GS V with a feed (65, 66) and the full cuts (0, 48)', () => {
    assert.deepEqual(parse(bytes([0x41, 0x0a], [0x1d, 0x56, 66, 3])).commands.at(-1), { cmd: 'cut', mode: 66, feed: 3 });
    for (const m of [0, 48, 49]) assert.equal(parse(bytes([0x41, 0x0a], [0x1d, 0x56, m])).names().at(-1), 'cut');
  });

  it('accepts a stream that prints nothing and has no cut', () => {
    assert.deepEqual(parse(bytes([0x1b, 0x40])).names(), ['init']);
    assert.deepEqual(parse(new Uint8Array(0)).names(), []);
  });

  const refusals = {
    'an unknown ESC command': [bytes([0x1b, 0x40], [0x1b, 0x2a, 0], CUT), /Unknown ESC command 0x2A at byte 2/],
    'an unknown GS command': [bytes([0x1d, 0x28, 0x41], CUT), /Unknown GS command 0x28 at byte 0/],
    'a GS v that is not GS v 0': [bytes([0x1d, 0x76, 0x31, 0, 1, 0, 1, 0, 0xff], CUT), /Unknown GS v function at byte 0/],
    'an unknown GS V mode': [bytes([0x41, 0x0a], [0x1d, 0x56, 7]), /Unknown GS V mode 7 at byte 2/],
    'a byte outside printable ASCII': [bytes([0x41, 0x09, 0x0a], CUT), /Unexpected byte 0x09 at byte 1/],
    'a high byte': [bytes([0xe1, 0x0a], CUT), /Unexpected byte 0xE1 at byte 0/],
    'a stream that ends inside a command': [bytes([0x1b, 0x40], [0x1b, 0x64]), /ends inside a command at byte 2/],
    'a raster shorter than it says': [bytes([0x1d, 0x76, 0x30, 0, 2, 0, 2, 0, 1, 2, 3]), /ends inside a command at byte 0/],
    'a receipt left in the printer (no cut)': [bytes([0x1b, 0x40], [0x41, 0x0a]), /ends without a cut/],
    'text with no line feed and no cut': [bytes([0x41]), /ends without a cut/],
    'bands of different widths in one receipt': [
      bytes([0x1d, 0x76, 0x30, 0, 1, 0, 1, 0, 0xff], [0x1d, 0x76, 0x30, 0, 2, 0, 1, 0, 0xff, 0xff], CUT),
      /different widths/,
    ],
  };
  for (const [what, [input, message]] of Object.entries(refusals)) {
    it(`refuses ${what}`, () => {
      assert.throws(() => parse(input), (err) => err instanceof EscPosError && message.test(err.message));
    });
  }
});
