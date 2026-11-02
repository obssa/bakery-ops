// The bridge's log (phase-5 plan 5.3b): one line per event, "<ISO time> <LEVEL> <event> <fields as JSON>", in a file that rotates by
// size (bridge.log, bridge.log.1 ... bridge.log.<keep>). It never holds a token: every line is scrubbed of the secrets it was told
// about (the bridge token, a user's token passing through the local mode) and of anything that follows "Bridge " or "Bearer ".
// Writes are synchronous: a line is on disk before the next step, and the bridge writes a few lines a minute at most.
import fs from 'node:fs';
import path from 'node:path';

const AUTH = /\b(Bridge|Bearer)(\s+)(?!\[redacted\])[^\s"',;]+/g;

export function redact(line, secrets) {
  let out = line;
  for (const s of secrets) if (s) out = out.split(s).join('[redacted]');
  return out.replace(AUTH, '$1$2[redacted]');
}

export function createLogger({ file, maxBytes = 1_048_576, keep = 5, secrets = [], console: toConsole = null, now = () => new Date() }) {
  const known = new Set(secrets.filter((s) => typeof s === 'string' && s.length >= 4));
  fs.mkdirSync(path.dirname(file), { recursive: true });
  let size = fs.existsSync(file) ? fs.statSync(file).size : 0;

  function rotate() {
    for (let n = keep; n >= 1; n--) {
      const from = n === 1 ? file : `${file}.${n - 1}`;
      if (fs.existsSync(from)) fs.renameSync(from, `${file}.${n}`);
    }
    size = 0;
  }

  function write(level, event, fields) {
    const extra = fields && Object.keys(fields).length > 0 ? ` ${JSON.stringify(fields)}` : '';
    const line = `${redact(`${now().toISOString()} ${level} ${event}${extra}`, known)}\n`;
    const bytes = Buffer.byteLength(line);
    try {
      if (size > 0 && size + bytes > maxBytes) rotate();
      fs.appendFileSync(file, line);
      size += bytes;
    } catch {
      // a full disk or a locked file must not stop the printing; the console still gets the line
    }
    if (toConsole) toConsole(line.trimEnd());
  }

  return {
    info: (event, fields) => write('INFO', event, fields),
    warn: (event, fields) => write('WARN', event, fields),
    error: (event, fields) => write('ERROR', event, fields),
    /** Never write this string again (a token read from its file, a user's token in a local print). */
    addSecret(secret) {
      if (typeof secret === 'string' && secret.length >= 4) known.add(secret);
    },
    forgetSecret(secret) {
      known.delete(secret);
    },
  };
}
