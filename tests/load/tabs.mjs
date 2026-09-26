// Simulated browser tabs: each fetches a Tether page, opens its live connection, mounts, and
// counts the patches it gets. Node 18+, no dependencies.
//   node tabs.mjs http://HOST:PORT/fast?rate=50 TABS SECONDS
import net from 'node:net';
import crypto from 'node:crypto';

const [pageUrl, tabsArg = '100', secondsArg = '10'] = process.argv.slice(2);
const tabs = +tabsArg;
const seconds = +secondsArg;
const url = new URL(pageUrl);

const html = await (await fetch(pageUrl)).text();
const mount = html.match(/<script type="application\/json" id="tether-mount">(.*?)<\/script>/s)[1];

let frames = 0, bytes = 0, open = 0, failed = 0, closed = 0;
const perTab = [];

function tab(i) {
  perTab[i] = 0;
  const socket = net.connect(+url.port, url.hostname);
  socket.setNoDelay(true);
  let buffer = Buffer.alloc(0);
  let upgraded = false;
  socket.on('connect', () => {
    socket.write(`GET /_tether/live HTTP/1.1\r\nHost: ${url.host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ${crypto.randomBytes(16).toString('base64')}\r\nSec-WebSocket-Version: 13\r\n\r\n`);
  });
  socket.on('data', (data) => {
    buffer = Buffer.concat([buffer, data]);
    if (!upgraded) {
      const end = buffer.indexOf('\r\n\r\n');
      if (end < 0) return;
      if (!buffer.subarray(0, 12).toString().includes('101')) { failed++; socket.destroy(); return; }
      upgraded = true;
      open++;
      buffer = buffer.subarray(end + 4);
      send(socket, mount);
    }
    // Server frames are unmasked
    for (;;) {
      if (buffer.length < 2) return;
      let length = buffer[1] & 0x7f, offset = 2;
      if (length === 126) { if (buffer.length < 4) return; length = buffer.readUInt16BE(2); offset = 4; }
      else if (length === 127) { if (buffer.length < 10) return; length = Number(buffer.readBigUInt64BE(2)); offset = 10; }
      if (buffer.length < offset + length) return;
      frames++; perTab[i]++; bytes += length;
      buffer = buffer.subarray(offset + length);
    }
  });
  socket.on('error', () => { failed++; });
  socket.on('close', () => { closed++; });
  return socket;
}

function send(socket, text) {
  const payload = Buffer.from(text);
  const mask = crypto.randomBytes(4);
  const head = payload.length < 126 ? Buffer.from([0x81, 0x80 | payload.length]) : Buffer.from([0x81, 0x80 | 126, payload.length >> 8, payload.length & 255]);
  const masked = Buffer.from(payload.map((b, j) => b ^ mask[j % 4]));
  socket.write(Buffer.concat([head, mask, masked]));
}

const sockets = [];
for (let i = 0; i < tabs; i++) {
  sockets.push(tab(i));
  if (i % 50 === 49) await new Promise((r) => setTimeout(r, 20)); // don't SYN-flood
}
await new Promise((r) => setTimeout(r, 1000)); // settle
const f0 = frames, b0 = bytes, t0 = Date.now();
const snapshot = [...perTab];
await new Promise((r) => setTimeout(r, seconds * 1000));
const dt = (Date.now() - t0) / 1000;
const rates = perTab.map((n, i) => (n - snapshot[i]) / dt).sort((a, b) => a - b);
const pct = (p) => rates[Math.min(rates.length - 1, Math.floor(p * rates.length))].toFixed(1);
console.log(`tabs ${tabs}: open ${open}, failed ${failed}, closed ${closed}; ${((frames - f0) / dt).toFixed(0)} frames/s total, ${((bytes - b0) / dt / 1024).toFixed(0)} KiB/s; per tab: min ${pct(0)} median ${pct(0.5)} p99 ${pct(0.99)} max ${rates[rates.length - 1].toFixed(1)} frames/s`);
sockets.forEach((s) => s.destroy());
process.exit(0);
