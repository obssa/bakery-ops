// A network receipt printer on TCP for the tests (phase-5 plan 5.3b): it answers the real-time status queries DLE EOT n that come
// before a job, then keeps every byte of the job and reads it back through the JS emulator, which refuses anything the server's
// tests/Support/EscPosEmulator.php refuses. Modes:
//   ready      prints
//   paper_out  answers DLE EOT with "roll paper end" (a real printer would take the bytes and print nothing)
//   cover_open answers DLE EOT with "cover open"
//   silent     never answers DLE EOT (a printer without status), prints
//   hangup     closes the connection as soon as the first status query arrives
import net from 'node:net';
import { EscPosEmulator } from '../../src/escpos-emulator.js';

const DLE = 0x10;
const EOT = 0x04;

function statusByte(mode, n) {
  let b = 0x12; // bits 1 and 4 are always set, 0 and 7 always clear
  if (n === 1 && (mode === 'paper_out' || mode === 'cover_open')) b |= 0x08; // offline
  if (n === 2 && mode === 'cover_open') b |= 0x04;
  if (n === 2 && mode === 'paper_out') b |= 0x20; // printing stopped: paper end
  if (n === 4 && mode === 'paper_out') b |= 0x60; // roll paper end
  return b;
}

export async function startFakePrinter({ mode = 'ready' } = {}) {
  const printer = {
    mode,
    host: '127.0.0.1',
    port: 0,
    /** one entry per connection: {queries: [n...], bytes: Buffer, printout: EscPosEmulator|null, error: string|null, openedAt, closedAt} */
    connections: [],
    /** the connections that carried job bytes */
    get jobs() {
      return this.connections.filter((c) => c.bytes.length > 0);
    },
  };
  const sockets = new Set();
  const server = net.createServer((socket) => {
    sockets.add(socket);
    const conn = { queries: [], bytes: Buffer.alloc(0), printout: null, error: null, openedAt: Date.now(), closedAt: null };
    printer.connections.push(conn);
    socket.on('close', () => {
      sockets.delete(socket);
      conn.closedAt = Date.now();
    });
    let head = Buffer.alloc(0); // bytes before the job starts (status queries)
    let jobStarted = false;
    socket.on('data', (chunk) => {
      if (jobStarted) {
        conn.bytes = Buffer.concat([conn.bytes, chunk]);
        return;
      }
      head = Buffer.concat([head, chunk]);
      while (head.length > 0) {
        if (head[0] === DLE) {
          if (head.length < 3) return; // wait for the rest of the query
          if (head[1] !== EOT) break;
          const n = head[2];
          conn.queries.push(n);
          head = head.subarray(3);
          if (printer.mode === 'hangup') {
            socket.destroy();
            return;
          }
          if (printer.mode !== 'silent') socket.write(Uint8Array.of(statusByte(printer.mode, n)));
          continue;
        }
        break;
      }
      if (head.length > 0) {
        jobStarted = true;
        conn.bytes = Buffer.concat([conn.bytes, head]);
        head = Buffer.alloc(0);
      }
    });
    socket.on('end', () => {
      if (conn.bytes.length > 0) {
        try {
          conn.printout = EscPosEmulator.parse(conn.bytes);
        } catch (err) {
          conn.error = err.message;
        }
      }
      socket.end();
    });
    socket.on('error', () => {});
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  printer.port = server.address().port;
  printer.close = () =>
    new Promise((resolve) => {
      for (const s of sockets) s.destroy();
      server.close(() => resolve());
    });
  return printer;
}

/** A port on which nothing listens (a printer that is switched off). */
export async function deadPort() {
  const server = net.createServer();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  await new Promise((resolve) => server.close(resolve));
  return port;
}
