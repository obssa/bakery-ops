import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { PrinterError, createPrinter, decodeStatus, failureKeyFor, uncPath } from '../src/printer.js';
import { deadPort, startFakePrinter } from './support/fake-printer.js';
import { receipt } from './support/escpos.js';
import { FAST, tempDir } from './support/setup.js';

const rejectsWith = (promise, key) => assert.rejects(promise, (err) => err instanceof PrinterError && err.key === key);

describe('a TCP printer (port 9100)', () => {
  let printer;
  before(async () => {
    printer = await startFakePrinter();
  });
  after(() => printer.close());

  const tcp = (extra = {}) => createPrinter({ type: 'tcp', host: printer.host, port: printer.port, statusCheck: true, ...extra }, { timing: FAST });

  it('asks the status (DLE EOT 2, DLE EOT 4) and then writes the bytes once', async () => {
    printer.mode = 'ready';
    const before = printer.connections.length;
    const bytes = receipt({ copies: 2 });
    await tcp().write(bytes);
    const [conn] = printer.connections.slice(before);
    assert.equal(printer.connections.length, before + 1);
    assert.deepEqual(conn.queries, [2, 4]);
    assert.deepEqual(conn.bytes, bytes);
    assert.equal(conn.error, null);
    assert.equal(conn.printout.bitmaps().length, 2);
  });

  it('reports paper_out and sends nothing when the roll is empty', async () => {
    printer.mode = 'paper_out';
    const before = printer.connections.length;
    await rejectsWith(tcp().write(receipt()), 'paper_out');
    assert.equal(printer.connections.slice(before)[0].bytes.length, 0);
  });

  it('reports cover_open', async () => {
    printer.mode = 'cover_open';
    await rejectsWith(tcp().write(receipt()), 'cover_open');
  });

  it('prints anyway when the printer does not answer status queries', async () => {
    printer.mode = 'silent';
    const notes = [];
    const before = printer.connections.length;
    await createPrinter({ type: 'tcp', host: printer.host, port: printer.port, statusCheck: true }, { timing: FAST, note: (e) => notes.push(e) }).write(
      receipt(),
    );
    assert.equal(printer.connections.slice(before)[0].printout.bitmaps().length, 1);
    assert.deepEqual(notes, ['printer.status_unanswered']);
  });

  it('skips the status queries when statusCheck is false', async () => {
    printer.mode = 'paper_out';
    const before = printer.connections.length;
    await tcp({ statusCheck: false }).write(receipt());
    assert.deepEqual(printer.connections.slice(before)[0].queries, []);
  });

  it('reports offline when the printer drops the connection', async () => {
    printer.mode = 'hangup';
    await rejectsWith(tcp().write(receipt()), 'offline');
  });

  it('reports offline when nothing listens (the printer is off)', async () => {
    const port = await deadPort();
    await rejectsWith(createPrinter({ type: 'tcp', host: '127.0.0.1', port, statusCheck: true }, { timing: FAST }).write(receipt()), 'offline');
  });
});

describe('a device path or a Windows shared printer', () => {
  it('writes the bytes to the device', async () => {
    const file = path.join(tempDir(), 'lp0');
    fs.writeFileSync(file, '');
    const bytes = receipt();
    await createPrinter({ type: 'device', path: file }, { timing: FAST }).write(bytes);
    assert.deepEqual(fs.readFileSync(file), bytes);
  });

  it('reports offline when the device is not there, and never creates it', async () => {
    const file = path.join(tempDir(), 'lp9');
    await rejectsWith(createPrinter({ type: 'device', path: file }, { timing: FAST }).write(receipt()), 'offline');
    assert.equal(fs.existsSync(file), false);
  });

  it('names a Windows shared printer by its UNC path', () => {
    assert.equal(uncPath({ name: 'Receipt', host: 'localhost' }), '\\\\localhost\\Receipt');
    assert.equal(uncPath({ name: 'POS 80', host: 'TILL-1' }), '\\\\TILL-1\\POS 80');
  });
});

describe('what a failure is called', () => {
  it('reads the DLE EOT answers', () => {
    assert.equal(decodeStatus(0x12, 0x12), null);
    assert.equal(decodeStatus(0x12 | 0x20, 0x12 | 0x60), 'paper_out');
    assert.equal(decodeStatus(0x12, 0x12 | 0x60), 'paper_out');
    assert.equal(decodeStatus(0x12 | 0x04, 0x12), 'cover_open');
    assert.equal(decodeStatus(0x12 | 0x40, 0x12), 'unknown');
    assert.equal(decodeStatus(0x12 | 0x08, 0x12 | 0x0c), null, 'paper near its end and the feed button still print');
    assert.equal(decodeStatus(0xff, 0x00), null, 'an answer that is not a status byte is ignored');
  });

  it('calls a network or device error offline and anything else unknown', () => {
    for (const code of ['ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH', 'ETIMEDOUT', 'ECONNRESET', 'EPIPE', 'ENOTFOUND', 'ENOENT', 'EBUSY', 'ENODEV', 'ENXIO', 'EACCES', 'EPERM', 'EIO']) {
      assert.equal(failureKeyFor(Object.assign(new Error(code), { code })), 'offline', code);
    }
    assert.equal(failureKeyFor(new Error('something else')), 'unknown');
    assert.equal(failureKeyFor(new PrinterError('cover_open', 'x')), 'cover_open');
  });
});
