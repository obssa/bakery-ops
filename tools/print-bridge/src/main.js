#!/usr/bin/env node
// print-bridge (phase-5 plan 5.3b, docs/runbook/print-bridge.md)
//
//   node src/main.js [--config bridge.config.json]                 run the bridge until stopped (what Task Scheduler starts)
//   node src/main.js --serve 127.0.0.1:9180                        also the local mode for the till page on this computer (D11)
//   node src/main.js --check                                       does the API take the token? (one poll, claims nothing)
//   node src/main.js --test-print                                  does the printer print? (a test receipt, no API)
//   node src/main.js --version
//
// Exit codes: 0 done, 1 refused or not printed, 2 a wrong config, token file or argument.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { VERSION, createApi } from './api.js';
import { createBridge, DEFAULT_TIMING } from './bridge.js';
import { ConfigError, loadConfig, readToken } from './config.js';
import { parseServeAddress } from './local.js';
import { createPrinter, describePrinter, failureKeyFor } from './printer.js';
import { testReceipt } from './test-receipt.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const USAGE = `print-bridge ${VERSION}
  --config <file>     bridge.config.json (default: the one beside src/)
  --serve [host:port] also take prints from the till page on this computer (default 127.0.0.1:9180)
  --check             ask the API once whether it takes the token
  --test-print        print a test receipt
  --version`;

function args(argv) {
  // --serve may come without its address
  const list = [...argv];
  const i = list.indexOf('--serve');
  if (i !== -1 && (i === list.length - 1 || list[i + 1].startsWith('--'))) list.splice(i + 1, 0, '127.0.0.1:9180');
  return parseArgs({
    args: list,
    options: {
      config: { type: 'string', short: 'c' },
      serve: { type: 'string' },
      check: { type: 'boolean' },
      'test-print': { type: 'boolean' },
      version: { type: 'boolean' },
      help: { type: 'boolean', short: 'h' },
    },
    strict: true,
    allowPositionals: false,
  }).values;
}

async function main() {
  let opts;
  try {
    opts = args(process.argv.slice(2));
  } catch (err) {
    console.error(`${err.message}\n\n${USAGE}`);
    return 2;
  }
  if (opts.version) {
    console.log(`print-bridge ${VERSION}`);
    return 0;
  }
  if (opts.help) {
    console.log(USAGE);
    return 0;
  }

  let config;
  try {
    config = loadConfig(opts.config ?? path.join(HERE, '..', 'bridge.config.json'));
    if (opts.serve) parseServeAddress(opts.serve);
  } catch (err) {
    if (err instanceof ConfigError) {
      console.error(err.message);
      return 2;
    }
    throw err;
  }

  if (opts.check) {
    let token;
    try {
      token = readToken(config.tokenFile);
    } catch (err) {
      console.error(err.message);
      return 2;
    }
    const api = createApi({ baseUrl: config.apiBaseUrl, token: () => token, timeoutMs: 15_000 });
    try {
      const next = await api.next();
      console.log(next ? `token accepted: job ${next.jobId} is waiting` : 'token accepted: no job is waiting');
      return 0;
    } catch (err) {
      const hint = err.challenged ? ' (Cloudflare challenged the request: see the runbook)' : '';
      console.error(`refused: ${err.key ?? err.message}${err.status ? ` (HTTP ${err.status})` : ''}${err.code ? ` ${err.code}` : ''}${hint}`);
      return 1;
    }
  }

  if (opts['test-print']) {
    const printer = createPrinter(config.printer, { timing: DEFAULT_TIMING, note: (e) => console.log(e) });
    const where = describePrinter(config.printer);
    try {
      await printer.write(testReceipt({ width: config.width ?? 576, printer: where, now: new Date() }));
      console.log(`test receipt printed on ${where}`);
      return 0;
    } catch (err) {
      console.error(`not printed: ${failureKeyFor(err)} (${err.message})`);
      return 1;
    }
  }

  const bridge = createBridge({ config, serve: opts.serve ?? null, console: (line) => console.log(line) });
  await bridge.start();
  const stop = async () => {
    await bridge.stop();
    process.exit(0);
  };
  for (const signal of ['SIGINT', 'SIGTERM', 'SIGBREAK']) process.once(signal, stop);
  return null; // keep running
}

main().then(
  (code) => {
    if (code !== null) process.exitCode = code;
  },
  (err) => {
    console.error(err?.stack ?? String(err));
    process.exitCode = 1;
  },
);
