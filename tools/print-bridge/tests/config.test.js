import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { ConfigError, loadConfig, parseConfig, readToken } from '../src/config.js';
import { TOKEN, tempDir, writeConfig } from './support/setup.js';

const base = {
  apiBaseUrl: 'https://bakery.example/api/v1/',
  tokenFile: 'bridge.token',
  printer: { type: 'tcp', host: '192.0.2.10' },
};

describe('bridge.config.json', () => {
  it('reads the example config shipped beside the program', () => {
    const config = loadConfig(new URL('../bridge.config.example.json', import.meta.url));
    assert.equal(config.apiBaseUrl, 'https://bakery.example/api/v1');
    assert.equal(config.printer.type, 'tcp');
    assert.equal(config.printer.port, 9100);
  });

  it('resolves the token and log files against the config folder and fills the defaults', () => {
    const dir = tempDir();
    const c = parseConfig(base, dir);
    assert.equal(c.apiBaseUrl, 'https://bakery.example/api/v1');
    assert.equal(c.tokenFile, path.join(dir, 'bridge.token'));
    assert.deepEqual(c.printer, { type: 'tcp', host: '192.0.2.10', port: 9100, statusCheck: true });
    assert.equal(c.width, null);
    assert.equal(c.log.file, path.join(dir, 'logs', 'bridge.log'));
    assert.equal(c.log.maxBytes, 1_048_576);
    assert.equal(c.log.keep, 5);
    assert.deepEqual(c.serve.origins, []);
  });

  it('takes the three kinds of printer', () => {
    assert.deepEqual(parseConfig({ ...base, printer: { type: 'windows', name: 'Receipt' } }, '.').printer, {
      type: 'windows',
      name: 'Receipt',
      host: 'localhost',
    });
    assert.deepEqual(parseConfig({ ...base, printer: { type: 'device', path: '/dev/usb/lp0' } }, '.').printer, {
      type: 'device',
      path: '/dev/usb/lp0',
    });
    assert.equal(parseConfig({ ...base, printer: { type: 'tcp', host: '192.0.2.10', port: 9101, statusCheck: false } }, '.').printer.port, 9101);
  });

  it('takes 576 or 384 dots, or no width (the receipt settings decide)', () => {
    assert.equal(parseConfig({ ...base, width: 576 }, '.').width, 576);
    assert.equal(parseConfig({ ...base, width: 384 }, '.').width, 384);
    assert.equal(parseConfig({ ...base, width: null }, '.').width, null);
  });

  const wrong = {
    'no API address': { ...base, apiBaseUrl: undefined },
    'an API address over plain http to another computer': { ...base, apiBaseUrl: 'http://bakery.example/api/v1' },
    'an API address that is not a URL': { ...base, apiBaseUrl: 'bakery' },
    'no token file': { ...base, tokenFile: '' },
    'no printer': { ...base, printer: undefined },
    'an unknown kind of printer': { ...base, printer: { type: 'usb' } },
    'a tcp printer without a host': { ...base, printer: { type: 'tcp' } },
    'a tcp port out of range': { ...base, printer: { type: 'tcp', host: 'x', port: 70000 } },
    'a Windows printer without a name': { ...base, printer: { type: 'windows' } },
    'a Windows printer name with a backslash': { ...base, printer: { type: 'windows', name: 'a\\b' } },
    'a device without a path': { ...base, printer: { type: 'device' } },
    'a width that is not 576 or 384': { ...base, width: 512 },
    'a log size below 10 KB': { ...base, log: { maxBytes: 100 } },
    'origins that are not URLs': { ...base, serve: { origins: ['bakery.example'] } },
    'an origin with a path': { ...base, serve: { origins: ['https://bakery.example/app'] } },
    'an unknown key': { ...base, tokn: 'x' },
  };
  for (const [what, raw] of Object.entries(wrong)) {
    it(`refuses ${what}`, () => {
      assert.throws(() => parseConfig(raw, '.'), ConfigError);
    });
  }

  it('lets plain http reach this computer only (the tests, a local proxy)', () => {
    assert.equal(parseConfig({ ...base, apiBaseUrl: 'http://127.0.0.1:8080/api/v1' }, '.').apiBaseUrl, 'http://127.0.0.1:8080/api/v1');
    assert.equal(parseConfig({ ...base, apiBaseUrl: 'http://localhost/api/v1' }, '.').apiBaseUrl, 'http://localhost/api/v1');
  });

  it('says which file is wrong when the JSON does not parse', () => {
    const dir = tempDir();
    const file = path.join(dir, 'bridge.config.json');
    fs.writeFileSync(file, '{ "apiBaseUrl": ');
    assert.throws(() => loadConfig(file), (err) => err instanceof ConfigError && err.message.includes('bridge.config.json'));
  });
});

describe('the token file', () => {
  it('trims the line end a text editor leaves', () => {
    const { tokenPath } = writeConfig({ api: 'http://127.0.0.1:1/api/v1', printer: { type: 'tcp', host: '127.0.0.1' } });
    assert.equal(readToken(tokenPath), TOKEN);
  });

  it('refuses an empty token, a token with spaces, and a missing file, without echoing the file', () => {
    const dir = tempDir();
    const file = path.join(dir, 't');
    fs.writeFileSync(file, '  \n');
    assert.throws(() => readToken(file), ConfigError);
    fs.writeFileSync(file, 'Bridge abc def');
    assert.throws(
      () => readToken(file),
      (err) => err instanceof ConfigError && !err.message.includes('abc'),
    );
    assert.throws(() => readToken(path.join(dir, 'missing')), ConfigError);
  });
});
