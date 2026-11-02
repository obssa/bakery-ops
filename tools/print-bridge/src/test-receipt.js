// The test print of the runbook (`--test-print`): a checker-board stripe as a raster (the real receipts are pictures, so this proves the
// printer takes GS v 0 at this width) and a few lines in the printer's own font, then a feed and a partial cut. Only the commands the
// emulator accepts, so the tests can read it back.
import { VERSION } from './api.js';

const ascii = (s) => s.replace(/[^\x20-\x7e]/g, '?');

function wrap(text, cols) {
  const lines = [];
  let line = '';
  for (const word of text.split(' ')) {
    if (line && line.length + 1 + word.length > cols) {
      lines.push(line);
      line = word;
    } else {
      line = line ? `${line} ${word}` : word;
    }
  }
  if (line) lines.push(line);
  return lines.map((l) => l.slice(0, cols));
}

export function testReceipt({ width = 576, printer = '', now = new Date() } = {}) {
  const cols = width === 384 ? 32 : 48;
  const bytesPerRow = width / 8;
  const rows = 48;
  const stripe = new Uint8Array(bytesPerRow * rows);
  for (let y = 0; y < rows; y++) {
    for (let b = 0; b < bytesPerRow; b++) stripe[y * bytesPerRow + b] = (b + (y >> 3)) % 2 === 0 ? 0xff : 0x00;
  }
  const lines = [
    `Paper: ${width} dots (${width === 384 ? 58 : 80} mm)`,
    `Printer: ${printer}`,
    `Time: ${now.toISOString()}`,
    `print-bridge ${VERSION}`,
    '-'.repeat(cols),
    ...wrap('The stripe above is an even checker board and this text is sharp: the printer is ready.', cols),
  ].map((l) => ascii(l).slice(0, cols));

  const parts = [
    Uint8Array.of(0x1b, 0x40, 0x1b, 0x74, 0x00), // ESC @, ESC t 0
    Uint8Array.of(0x1d, 0x76, 0x30, 0x00, bytesPerRow & 0xff, bytesPerRow >> 8, rows & 0xff, rows >> 8),
    stripe,
    Uint8Array.of(0x0a, 0x1b, 0x61, 1, 0x1b, 0x45, 1),
    Buffer.from('PRINT BRIDGE TEST', 'latin1'),
    Uint8Array.of(0x0a, 0x1b, 0x45, 0, 0x1b, 0x61, 0),
    ...lines.flatMap((l) => [Buffer.from(l, 'latin1'), Uint8Array.of(0x0a)]),
    Uint8Array.of(0x1b, 0x64, 4, 0x1d, 0x56, 0x01), // ESC d 4, GS V 1
  ];
  return Buffer.concat(parts);
}
