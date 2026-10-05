// Tether::from() in a real browser: the plain playground (playground/plain), no framework.
// Usage: node tests/browser/from.mjs http://127.0.0.1:PORT/
import net from 'node:net';
import { launch } from './cdp.mjs';

const url = process.argv[2];
const { host, hostname, port } = new URL(url);
const { chrome, page } = await launch();
const results = [];
const check = async (name, fn) => {
  try {
    await fn();
    results.push(`ok    ${name}`);
  } catch (e) {
    results.push(`FAIL  ${name}: ${e.message}`);
  }
};
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
const text = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent`);

// Raw handshake to the live URL $path: the status line, and the code of the close frame that follows
const handshake = (path, origin, cookie = '') => new Promise((resolve, reject) => {
  let bytes = Buffer.alloc(0);
  const socket = net.connect({ host: hostname, port: +port }, () => socket.write(`GET ${path} HTTP/1.1\r\nHost: ${host}\r\nOrigin: ${origin}\r\nCookie: ${cookie}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n`));
  const finish = () => {
    const end = bytes.indexOf('\r\n\r\n');
    const body = end < 0 ? Buffer.alloc(0) : bytes.subarray(end + 4);
    resolve({ status: bytes.toString().split('\r\n')[0], close: body.length >= 4 && body[0] === 0x88 ? body.readUInt16BE(2) : null });
    socket.destroy();
  };
  socket.on('data', (d) => {
    bytes = Buffer.concat([bytes, d]);
    const end = bytes.indexOf('\r\n\r\n');
    if (end >= 0 && (!bytes.toString().startsWith('HTTP/1.1 101') || bytes.length >= end + 8)) finish();
  });
  socket.on('error', reject);
});

// Every WebSocket the page opens is kept in window.sockets; at /chat/lobby they are sent to a room that does not exist
await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(address, ...rest) {
      super(location.pathname === '/chat/lobby' ? address.replace('/chat/lobby', '/chat/nowhere') : address, ...rest);
      window.sockets.push(this);
    }
  };
})()`);

try {
  await page.goto(new URL('/', url).href + '?start=3');
  await check('the counter renders on the server, goes live and a click works', async () => {
    await page.until(`window.sockets.length === 1`);
    if (await text('#count') !== '3') throw new Error('count ' + await text('#count'));
    await page.until(`document.querySelector('script[data-tether]').parentNode === document.head`);
    // The live mount looks like the server's render, and clicks before it are dropped: click until one counts
    for (let i = 0; i < 40 && await text('#count') !== '4'; ++i) {
      await page.eval(`document.getElementById('inc').click()`);
      await pause(100);
    }
    if (await text('#count') !== '4') throw new Error('count ' + await text('#count'));
  });

  await check('a reconnect runs the route again, for the URL the page was rendered for: the state starts over', async () => {
    await page.eval(`history.pushState(null, '', '/elsewhere'); window.sockets.at(-1).close()`);
    await page.until(`window.sockets.length === 2 && document.getElementById('count').textContent === '3'`);
    await page.eval(`history.back()`);
  });

  await page.goto(new URL('/login?name=ada', url).href);
  await check('a deep link renders on the server, goes live, and the route\'s parameter reaches the live tab', async () => {
    await page.goto(new URL('/chat/dev', url).href);
    if (await text('#chat') === null || await page.eval(`document.getElementById('chat').dataset.room`) !== 'dev') throw new Error('not the dev room');
    await page.until(`document.getElementById('chat').dataset.status === 'live' && document.getElementById('path').textContent === '/chat/dev'`);
  });

  await check('events reach the component, which publishes to every tab of the room', async () => {
    await page.eval(`(() => { const i = document.getElementById('text'); i.value = 'hi'; i.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await pause(100);
    await page.eval(`document.getElementById('text').dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))`);
    await page.until(`document.getElementById('lines').textContent === 'ada: hi'`);
  });

  await check('killing the socket: the client reconnects and the component starts over', async () => {
    const before = await page.eval(`window.sockets.length`);
    await page.eval(`window.sockets.at(-1).close()`);
    await page.until(`window.sockets.length === ${before + 1} && document.getElementById('lines').children.length === 0 && document.getElementById('chat').dataset.status === 'live'`);
  });

  await check('a route that redirects on the reconnect (signed out) sends the page there', async () => {
    await page.eval(`document.cookie = 'user=; Max-Age=0; Path=/'; window.sockets.at(-1).close()`);
    await page.until(`location.pathname === '/login'`, 5000);
  });

  await check('a page from another site can not open the live connection; the route answering not found closes with 1008', async () => {
    const own = await handshake('/chat/dev', new URL(url).origin, 'user=ada');
    const other = await handshake('/chat/dev', 'https://evil.example', 'user=ada');
    const missing = await handshake('/chat/nowhere', new URL(url).origin, 'user=ada');
    if (!own.status.includes('101') || !other.status.includes('403') || !missing.status.includes('101') || missing.close !== 1008) throw new Error(JSON.stringify([own, other, missing]));
  });

  await check('a client refused with 1008 stays static and does not reconnect', async () => {
    await page.goto(new URL('/login?name=ada', url).href);
    await page.until(`location.pathname === '/chat/lobby' && document.documentElement.hasAttribute('tether-offline')`);
    await pause(1500);
    const opens = await page.eval(`window.sockets.length`);
    if (opens !== 1) throw new Error(`${opens} connections`);
    if (await page.eval(`document.getElementById('chat').dataset.status`) !== 'static') throw new Error('the page changed');
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
