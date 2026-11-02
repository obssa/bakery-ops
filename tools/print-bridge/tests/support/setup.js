import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const TOKEN = 'bridge-token-aaaaaaaaaaaaaaaaaaaaaaaa';
export const OTHER_TOKEN = 'bridge-token-bbbbbbbbbbbbbbbbbbbbbbbb';
export const USER_TOKEN = '42|user-token-cccccccccccccccccccccccc';
export const DEVICE_ID = '0b9d3c55-8d0e-4f3c-9a51-7f4a2f0c1d11';

/** The bridge's waits, scaled down about 150 times so a test takes milliseconds (production: bridge.js DEFAULT_TIMING). */
export const FAST = Object.freeze({
  fastMs: 20,
  slowMs: 120,
  quietMs: 300,
  errorMaxMs: 160,
  unauthorizedMs: 100,
  reportWithinMs: 1500,
  reportRetryMs: 40,
  claimRetryMs: 30,
  requestMs: 1000,
  connectMs: 500,
  statusMs: 150,
  writeMs: 3000,
});

export function tempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'print-bridge-'));
}

/** Writes a token file and bridge.config.json into a fresh folder; returns the paths. */
export function writeConfig({ api, printer, width = 576, token = TOKEN, serve, dir = tempDir(), extra = {} } = {}) {
  fs.writeFileSync(path.join(dir, 'bridge.token'), `${token}\r\n`);
  const config = {
    apiBaseUrl: api,
    tokenFile: 'bridge.token',
    printer,
    width,
    log: { file: 'logs/bridge.log', maxBytes: 200_000, keep: 3 },
    ...(serve ? { serve } : {}),
    ...extra,
  };
  const configPath = path.join(dir, 'bridge.config.json');
  fs.writeFileSync(configPath, JSON.stringify(config, null, 2));
  return { dir, configPath, tokenPath: path.join(dir, 'bridge.token'), logDir: path.join(dir, 'logs') };
}

export async function waitFor(check, { timeoutMs = 4000, everyMs = 10, what = 'the condition' } = {}) {
  const until = Date.now() + timeoutMs;
  for (;;) {
    const value = await check();
    if (value) return value;
    if (Date.now() > until) throw new Error(`timed out waiting for ${what}`);
    await new Promise((r) => setTimeout(r, everyMs));
  }
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Every line of every log file in the folder (the current one and the rotated ones). */
export function logLines(logDir) {
  if (!fs.existsSync(logDir)) return [];
  return fs
    .readdirSync(logDir)
    .flatMap((f) => fs.readFileSync(path.join(logDir, f), 'utf8').split('\n'))
    .filter((l) => l !== '');
}
