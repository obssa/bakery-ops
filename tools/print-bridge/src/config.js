// bridge.config.json (phase-5 plan 5.3b): where the API is, where the token is, which printer, how wide. Relative paths are read from
// the config file's folder. The token lives in its own file, never in the config, so the config can be shown to anyone helping.
//
//   {
//     "apiBaseUrl": "https://bakery.example/api/v1",
//     "tokenFile": "bridge.token",
//     "printer": { "type": "tcp", "host": "192.0.2.10", "port": 9100, "statusCheck": true }
//              | { "type": "windows", "name": "Receipt", "host": "localhost" }
//              | { "type": "device", "path": "/dev/usb/lp0" },
//     "width": 576 | 384 | null,
//     "log": { "file": "logs/bridge.log", "maxBytes": 1048576, "keep": 5 },
//     "serve": { "origins": ["https://bakery.example"] }
//   }
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const WIDTHS = Object.freeze([576, 384]);
export const FAILURE_KEYS = Object.freeze(['paper_out', 'offline', 'cover_open', 'unknown']);
const KEYS = new Set(['$comment', 'apiBaseUrl', 'tokenFile', 'printer', 'width', 'log', 'serve']);
const LOOPBACK = new Set(['127.0.0.1', 'localhost', '[::1]']);

export class ConfigError extends Error {
  constructor(message) {
    super(message);
    this.name = 'ConfigError';
  }
}

const fail = (message) => {
  throw new ConfigError(message);
};
const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const text = (v) => typeof v === 'string' && v.trim() !== '';

export function loadConfig(file) {
  const where = file instanceof URL ? fileURLToPath(file) : path.resolve(String(file));
  let raw;
  try {
    raw = JSON.parse(fs.readFileSync(where, 'utf8').replace(/^﻿/, ''));
  } catch (err) {
    fail(`${where}: ${err.code === 'ENOENT' ? 'no such file' : err.message}`);
  }
  try {
    return parseConfig(raw, path.dirname(where));
  } catch (err) {
    if (err instanceof ConfigError) fail(`${where}: ${err.message}`);
    throw err;
  }
}

export function parseConfig(raw, baseDir) {
  if (!isObject(raw)) fail('the config is not a JSON object');
  for (const key of Object.keys(raw)) if (!KEYS.has(key)) fail(`unknown key "${key}"`);

  if (!text(raw.apiBaseUrl)) fail('apiBaseUrl is missing (the API address, ending in /api/v1)');
  let url;
  try {
    url = new URL(raw.apiBaseUrl);
  } catch {
    fail('apiBaseUrl is not a URL');
  }
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && LOOPBACK.has(url.hostname))) {
    fail('apiBaseUrl must be https:// (plain http only to this computer): the token must not travel in clear');
  }
  if (url.search || url.hash || url.username || url.password) fail('apiBaseUrl takes no query, fragment or user name');
  const apiBaseUrl = url.href.replace(/\/+$/, '');

  if (!text(raw.tokenFile)) fail('tokenFile is missing (the file that holds the bridge token)');

  return {
    apiBaseUrl,
    tokenFile: path.resolve(baseDir, raw.tokenFile),
    printer: parsePrinter(raw.printer),
    width: parseWidth(raw.width),
    log: parseLog(raw.log ?? {}, baseDir),
    serve: parseServe(raw.serve ?? {}),
  };
}

function parsePrinter(p) {
  if (!isObject(p)) fail('printer is missing: {"type": "tcp", "host": ...}, {"type": "windows", "name": ...} or {"type": "device", "path": ...}');
  if (p.type === 'tcp') {
    if (!text(p.host)) fail('printer.host is missing');
    const port = p.port ?? 9100;
    if (!Number.isInteger(port) || port < 1 || port > 65535) fail('printer.port is not a port number');
    if (p.statusCheck !== undefined && typeof p.statusCheck !== 'boolean') fail('printer.statusCheck is true or false');
    return { type: 'tcp', host: p.host.trim(), port, statusCheck: p.statusCheck ?? true };
  }
  if (p.type === 'windows') {
    if (!text(p.name)) fail('printer.name is missing (the share name of the Windows printer)');
    if (/[\\/]/.test(p.name)) fail('printer.name is the share name alone, without \\\\computer\\');
    const host = p.host ?? 'localhost';
    if (!text(host) || /[\\/]/.test(host)) fail('printer.host is a computer name');
    return { type: 'windows', name: p.name.trim(), host: host.trim() };
  }
  if (p.type === 'device') {
    if (!text(p.path)) fail('printer.path is missing (for example /dev/usb/lp0)');
    return { type: 'device', path: p.path };
  }
  return fail(`printer.type is tcp, windows or device, not ${JSON.stringify(p.type)}`);
}

function parseWidth(width) {
  if (width === undefined || width === null) return null;
  if (!WIDTHS.includes(width)) fail('width is 576 (80 mm paper), 384 (58 mm) or null (the receipt settings decide)');
  return width;
}

function parseLog(log, baseDir) {
  if (!isObject(log)) fail('log is an object');
  const maxBytes = log.maxBytes ?? 1_048_576;
  const keep = log.keep ?? 5;
  if (!Number.isInteger(maxBytes) || maxBytes < 10_240) fail('log.maxBytes is at least 10240');
  if (!Number.isInteger(keep) || keep < 1 || keep > 50) fail('log.keep is 1 to 50');
  return { file: path.resolve(baseDir, text(log.file) ? log.file : path.join('logs', 'bridge.log')), maxBytes, keep };
}

function parseServe(serve) {
  if (!isObject(serve)) fail('serve is an object');
  const origins = serve.origins ?? [];
  if (!Array.isArray(origins)) fail('serve.origins is a list of origins');
  return {
    origins: origins.map((o) => {
      let u;
      try {
        u = new URL(o);
      } catch {
        fail(`serve.origins: ${JSON.stringify(o)} is not an origin like https://bakery.example`);
      }
      if (u.origin !== String(o).replace(/\/$/, '')) fail(`serve.origins: ${JSON.stringify(o)} is an origin alone, without a path`);
      return u.origin;
    }),
  };
}

/** The bridge token: the one line of its file. The message never repeats the file's content. */
export function readToken(file) {
  let raw;
  try {
    raw = fs.readFileSync(file, 'utf8');
  } catch (err) {
    fail(`cannot read the token file ${file} (${err.code ?? err.message})`);
  }
  const token = raw.replace(/^﻿/, '').trim();
  if (token === '') fail(`the token file ${file} is empty: paste the token shown when the bridge was enrolled`);
  if (/\s/.test(token)) fail(`the token file ${file} holds more than the token (spaces or several lines)`);
  return token;
}
