import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createLogger, redact } from '../src/log.js';
import { TOKEN, USER_TOKEN, logLines, tempDir } from './support/setup.js';

describe('the log', () => {
  it('writes one line per event: the time, the level, the event and its fields as JSON', () => {
    const dir = tempDir();
    const log = createLogger({ file: path.join(dir, 'logs', 'bridge.log'), now: () => new Date('2026-11-02T08:00:00Z') });
    log.info('job.printed', { job_id: 4, order_number: 'A-012' });
    log.warn('printer.status_unanswered');
    log.error('api.unreachable', { code: 'ECONNREFUSED' });
    assert.deepEqual(logLines(path.join(dir, 'logs')), [
      '2026-11-02T08:00:00.000Z INFO job.printed {"job_id":4,"order_number":"A-012"}',
      '2026-11-02T08:00:00.000Z WARN printer.status_unanswered',
      '2026-11-02T08:00:00.000Z ERROR api.unreachable {"code":"ECONNREFUSED"}',
    ]);
  });

  it('rotates: bridge.log becomes bridge.log.1, and only `keep` old files stay', () => {
    const dir = tempDir();
    const file = path.join(dir, 'bridge.log');
    const log = createLogger({ file, maxBytes: 10_240, keep: 2 });
    for (let i = 0; i < 400; i++) log.info('poll.tick', { i, pad: 'x'.repeat(60) });
    const files = fs.readdirSync(dir).sort();
    assert.deepEqual(files, ['bridge.log', 'bridge.log.1', 'bridge.log.2']);
    for (const f of files) assert.ok(fs.statSync(path.join(dir, f)).size <= 10_240, `${f} is over the size`);
    const newest = fs.readFileSync(file, 'utf8').trim().split('\n').at(-1);
    assert.match(newest, /"i":399/);
  });

  it('never writes a secret it was told about, nor anything after Bridge or Bearer', () => {
    const dir = tempDir();
    const log = createLogger({ file: path.join(dir, 'bridge.log'), secrets: [TOKEN] });
    log.addSecret(USER_TOKEN);
    log.error('oops', { header: `Bridge ${TOKEN}`, raw: TOKEN, user: `Bearer ${USER_TOKEN}`, other: 'Bearer abc.def-ghi' });
    log.error(`message holding ${TOKEN}`);
    const all = logLines(dir).join('\n');
    assert.ok(!all.includes(TOKEN));
    assert.ok(!all.includes(USER_TOKEN));
    assert.ok(!all.includes('abc.def-ghi'));
    assert.match(all, /\[redacted\]/);
  });

  it('redact() leaves ordinary text alone', () => {
    assert.equal(redact('job.printed {"job_id":4}', [TOKEN]), 'job.printed {"job_id":4}');
    assert.equal(redact(`a ${TOKEN} b`, [TOKEN]), 'a [redacted] b');
    assert.equal(redact('Authorization: Bridge xyz', []), 'Authorization: Bridge [redacted]');
  });

  it('can also write to the console, redacted the same way', () => {
    const out = [];
    const log = createLogger({ file: path.join(tempDir(), 'b.log'), secrets: [TOKEN], console: (line) => out.push(line) });
    log.info('start', { token: TOKEN });
    assert.equal(out.length, 1);
    assert.ok(!out[0].includes(TOKEN));
  });
});
