// The receipt printer (phase-5 plan 5.3b, 10.2 Q3): a network printer on TCP port 9100, a Windows shared printer (written through its
// UNC path \\computer\share, the way `copy /b` does) or a device path (/dev/usb/lp0). write() sends the bytes once and resolves when
// they are with the printer; it rejects with a PrinterError whose key is one the API takes: paper_out, cover_open, offline, unknown.
//
// Only a TCP printer can say why it will not print: before the job the bridge asks DLE EOT 2 (offline cause: cover open, stopped at the
// paper end, an error) and DLE EOT 4 (roll paper sensor). A printer that does not answer within statusMs is printed to anyway (some
// cheap printers never answer). A Windows printer's spooler and a device file take the bytes without a word, so for them only
// "offline" (the printer or its share is not there) can be told.
import fs from 'node:fs';
import net from 'node:net';

const DLE_EOT_OFFLINE_CAUSE = Uint8Array.of(0x10, 0x04, 0x02);
const DLE_EOT_PAPER = Uint8Array.of(0x10, 0x04, 0x04);
const OFFLINE = new Set([
  'ECONNREFUSED',
  'ECONNRESET',
  'ECONNABORTED',
  'EHOSTUNREACH',
  'EHOSTDOWN',
  'ENETUNREACH',
  'ENETDOWN',
  'ETIMEDOUT',
  'EPIPE',
  'ENOTFOUND',
  'EAI_AGAIN',
  'EADDRNOTAVAIL',
  'ENOENT',
  'EBUSY',
  'ENODEV',
  'ENXIO',
  'EACCES',
  'EPERM',
  'EIO',
]);

export class PrinterError extends Error {
  constructor(key, message, cause) {
    super(message, cause ? { cause } : undefined);
    this.name = 'PrinterError';
    this.key = key;
  }
}

export function failureKeyFor(err) {
  if (err instanceof PrinterError) return err.key;
  return OFFLINE.has(err?.code) ? 'offline' : 'unknown';
}

/**
 * What the two status answers say stops the printing, or null. A status byte has bit 1 and bit 4 set and bits 0 and 7 clear; anything
 * else is not an answer and is ignored. Paper near its end (DLE EOT 4 bits 2-3) and the feed button (DLE EOT 2 bit 3) still print.
 */
export function decodeStatus(cause, paper) {
  const valid = (b) => Number.isInteger(b) && (b & 0x93) === 0x12;
  if (valid(paper) && (paper & 0x60) === 0x60) return 'paper_out';
  if (valid(cause)) {
    if (cause & 0x20) return 'paper_out';
    if (cause & 0x04) return 'cover_open';
    if (cause & 0x40) return 'unknown';
  }
  return null;
}

export function uncPath({ name, host = 'localhost' }) {
  return `\\\\${host}\\${name}`;
}

export function describePrinter(p) {
  if (p.type === 'tcp') return `tcp ${p.host}:${p.port}`;
  if (p.type === 'windows') return `windows ${uncPath(p)}`;
  return `device ${p.path}`;
}

/** @param {{connectMs: number, statusMs: number, writeMs: number}} timing  @param {(event: string) => void} note */
export function createPrinter(config, { timing, note = () => {} }) {
  return {
    type: config.type,
    describe: () => describePrinter(config),
    write: (bytes) => (config.type === 'tcp' ? tcpWrite(config, bytes, timing, note) : fileWrite(config, bytes, timing)),
  };
}

function connect({ host, port }, ms) {
  return new Promise((resolve, reject) => {
    const socket = net.connect({ host, port });
    const timer = setTimeout(() => {
      socket.destroy();
      reject(new PrinterError('offline', `no answer from the printer at ${host}:${port}`));
    }, ms);
    const onError = (err) => {
      clearTimeout(timer);
      socket.destroy();
      reject(new PrinterError(failureKeyFor(err), `the printer at ${host}:${port}: ${err.code ?? err.message}`, err));
    };
    socket.once('error', onError);
    socket.once('connect', () => {
      clearTimeout(timer);
      socket.off('error', onError);
      socket.setNoDelay(true);
      resolve(socket);
    });
  });
}

/** The bytes the printer sends back (status answers), one at a time, and whether it has hung up. */
function reader(socket) {
  const inbox = [];
  let wake = null;
  let closed = false;
  let error = null;
  const poke = () => wake?.();
  socket.on('data', (chunk) => {
    for (const b of chunk) inbox.push(b);
    poke();
  });
  socket.on('end', () => ((closed = true), poke()));
  socket.on('close', () => ((closed = true), poke()));
  socket.on('error', (err) => ((error = err), (closed = true), poke()));
  return {
    get closed() {
      return closed;
    },
    get error() {
      return error;
    },
    /** The next byte, or null when none came within ms; rejects when the printer hung up. */
    next(ms) {
      return new Promise((resolve, reject) => {
        let timer = null;
        const settle = () => {
          clearTimeout(timer);
          wake = null;
        };
        const check = () => {
          if (inbox.length > 0) {
            settle();
            resolve(inbox.shift());
          } else if (closed) {
            settle();
            reject(new PrinterError('offline', 'the printer closed the connection', error ?? undefined));
          }
        };
        timer = setTimeout(() => (settle(), resolve(null)), ms);
        wake = check;
        check();
      });
    },
  };
}

async function tcpWrite(config, bytes, timing, note) {
  const socket = await connect(config, timing.connectMs);
  const from = reader(socket);
  try {
    if (config.statusCheck) {
      socket.write(DLE_EOT_OFFLINE_CAUSE);
      const cause = await from.next(timing.statusMs);
      if (cause === null) {
        note('printer.status_unanswered');
      } else {
        socket.write(DLE_EOT_PAPER);
        const paper = await from.next(timing.statusMs);
        const problem = decodeStatus(cause, paper);
        if (problem) throw new PrinterError(problem, `the printer says ${problem}`);
      }
    }
    if (from.closed) throw new PrinterError('offline', 'the printer closed the connection', from.error ?? undefined);
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new PrinterError('offline', 'the printer did not take the receipt in time')), timing.writeMs);
      const onClose = () => {
        clearTimeout(timer);
        reject(new PrinterError(failureKeyFor(from.error ?? { code: 'ECONNRESET' }), 'the printer hung up during the receipt', from.error ?? undefined));
      };
      socket.once('close', onClose);
      socket.end(bytes, () => {
        clearTimeout(timer);
        socket.off('close', onClose);
        resolve();
      });
    });
    // everything is with the printer; give it a moment to close its side, then let go
    await new Promise((resolve) => {
      if (socket.destroyed) return resolve();
      const timer = setTimeout(resolve, 2000);
      socket.once('close', () => (clearTimeout(timer), resolve()));
    });
  } finally {
    socket.destroy();
  }
}

async function fileWrite(config, bytes, timing) {
  const target = config.type === 'windows' ? uncPath(config) : config.path;
  // O_WRONLY without O_CREAT: a printer that is not there is an error, never a new file
  const work = (async () => {
    const handle = await fs.promises.open(target, fs.constants.O_WRONLY);
    try {
      await handle.writeFile(bytes);
    } finally {
      await handle.close();
    }
  })();
  let timer;
  const late = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new PrinterError('offline', `${target} did not take the receipt in time`)), timing.writeMs);
  });
  try {
    await Promise.race([work, late]);
  } catch (err) {
    work.catch(() => {});
    if (err instanceof PrinterError) throw err;
    throw new PrinterError(failureKeyFor(err), `${target}: ${err.code ?? err.message}`, err);
  } finally {
    clearTimeout(timer);
  }
}
