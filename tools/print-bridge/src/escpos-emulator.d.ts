// Types for escpos-emulator.js, so a TypeScript spec (step 5.3c's Playwright print.spec.ts) can import it.

export class EscPosError extends Error {}

export class Bitmap {
  constructor(width: number, height: number, data: Uint8Array);
  readonly width: number;
  readonly height: number;
  readonly data: Uint8Array;
  bytesPerRow(): number;
  isBlack(x: number, y: number): boolean;
  blackDots(): number;
}

export type EscPosCommand =
  | { cmd: 'init' }
  | { cmd: 'feed'; lines: number }
  | { cmd: 'align' | 'bold' | 'codepage' | 'size'; n: number }
  | { cmd: 'raster'; mode: number; bytes: number; rows: number }
  | { cmd: 'cut'; mode: number; feed?: number }
  | { cmd: 'text' }
  | { cmd: 'lf' };

export type EscPosBytes = Uint8Array | ArrayBuffer | ArrayBufferView | string;

export class EscPosEmulator {
  commands: EscPosCommand[];
  static parse(bytes: EscPosBytes): EscPosEmulator;
  names(): string[];
  bitmaps(): Bitmap[];
  lines(): string[];
}

export function parse(bytes: EscPosBytes): EscPosEmulator;
