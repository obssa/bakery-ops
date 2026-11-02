// The command line the runbook uses: --check (does the API take the token?), --test-print (does the printer print?), and the bridge
// itself until it is stopped. Run as a child process, the way Task Scheduler runs it.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { EscPosEmulator } from '../src/escpos-emulator.js';
import { testReceipt } from '../src/test-receipt.js';
import { startFakeApi } from './support/fake-api.js';
import { startFakePrinter } from './support/fake-printer.js';
import { receipt } from './support/escpos.js';
import { OTHER_TOKEN, TOKEN, logLines, waitFor, writeConfig } from './support/setup.js';

const MAIN = fileURLToPath(new URL('../src/main.js', import.meta.url));

function run(args, { untilOutput } = {}) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [MAIN, ...args], { stdio: ['ignore', 'pipe', 'pipe'] });
    let out = '';
    let err = '';
    child.stdout.on('data', (c) => {
      out += c;
      if (untilOutput && untilOutput.test(out)) child.kill('SIGTERM');
    });
    child.stderr.on('data', (c) => (err += c));
    child.on('close', (code) => resolve({ code, out, err }));
  });
}

describe('print-bridge on the command line', () => {
  let api;
  let printer;
  before(async () => {
    api = await startFakeApi({ revoked: [OTHER_TOKEN] });
    printer = await startFakePrinter();
  });
  after(async () => {
    await api.close();
    await printer.close();
  });
  const config = (token = TOKEN) => writeConfig({ api: api.url, printer: { type: 'tcp', host: printer.host, port: printer.port }, token });

  it('--version prints the version', async () => {
    const r = await run(['--version']);
    assert.equal(r.code, 0);
    assert.match(r.out, /^print-bridge \d+\.\d+\.\d+/);
  });

  it('--check says the API took the token, and never prints it', async () => {
    const { configPath } = config();
    const r = await run(['--config', configPath, '--check']);
    assert.equal(r.code, 0, r.err);
    assert.match(r.out, /token accepted/i);
    assert.ok(!r.out.includes(TOKEN) && !r.err.includes(TOKEN));
    assert.equal(api.bridgeRequests().filter((q) => q.path.endsWith('/claim')).length, 0, '--check claims nothing');
  });

  it('--check names the refusal of a revoked token and exits 1', async () => {
    const { configPath } = config(OTHER_TOKEN);
    const r = await run(['--config', configPath, '--check']);
    assert.equal(r.code, 1);
    assert.match(r.out + r.err, /identity\.bridge\.revoked/);
    assert.ok(!(r.out + r.err).includes(OTHER_TOKEN));
  });

  it('--test-print prints a test receipt the emulator reads: a raster stripe and text, then a cut', async () => {
    const { configPath } = config();
    const before = printer.jobs.length;
    const r = await run(['--config', configPath, '--test-print']);
    assert.equal(r.code, 0, r.err);
    assert.match(r.out, /printed/i);
    const [job] = printer.jobs.slice(before);
    assert.equal(job.error, null);
    assert.equal(job.printout.bitmaps().length, 1);
    assert.equal(job.printout.bitmaps()[0].width, 576);
    assert.ok(job.printout.lines().some((l) => l.includes('PRINT BRIDGE TEST')));
    assert.equal(job.printout.names().at(-1), 'cut');
  });

  it('testReceipt() fits the paper: 48 columns at 576 dots, 32 at 384', () => {
    for (const [width, cols] of [
      [576, 48],
      [384, 32],
    ]) {
      const p = EscPosEmulator.parse(testReceipt({ width, printer: 'tcp 192.0.2.10:9100', now: new Date('2026-11-02T08:00:00Z') }));
      assert.equal(p.bitmaps()[0].width, width);
      for (const l of p.lines()) assert.ok(l.length <= cols, `${l.length} > ${cols}: ${l}`);
    }
  });

  it('a bad config exits 2 with the reason', async () => {
    const r = await run(['--config', fileURLToPath(new URL('./nothing.json', import.meta.url)), '--check']);
    assert.equal(r.code, 2);
    assert.match(r.err, /nothing\.json/);
  });

  it('runs the bridge until it is stopped', async () => {
    const { configPath, logDir } = config();
    const id = api.addJob({ bytes: receipt() });
    const r = await run(['--config', configPath], { untilOutput: /job\.printed/ });
    assert.equal(api.jobs.get(id).status, 'printed');
    assert.ok(!r.out.includes(TOKEN));
    await waitFor(() => logLines(logDir).some((l) => l.includes('job.printed')));
  });
});
