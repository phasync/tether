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

  await page.goto(new URL('/form', url).href);
  await page.until(`window.sockets.length === 1 && document.documentElement.hasAttribute('tether-live')`);
  await check('what the page did to its elements survives the server rendering again, unless the server changed it', async () => {
    await page.eval(`(() => {
      note.value = 'typed'; ok.checked = true; pick.value = 'b'; area.value = 'long text'; more.open = true;
      mark.classList.add('js'); kept.className = 'changed by script'; follows.className = 'changed by script';
    })()`);
    for (let i = 0; i < 40 && await text('#ticks') !== '1'; ++i) {
      await page.eval(`tick.click()`);
      await pause(100);
    }
    const now = await page.eval(`JSON.stringify([note.value, ok.checked, pick.value, area.value, more.open, mark.className])`);
    if (now !== JSON.stringify(['typed', true, 'b', 'long text', true, 'shown js'])) throw new Error(now);
  });

  await check('tether-keep lists the attributes the server never overwrites', async () => {
    await page.eval(`tick.click()`);
    await page.until(`ticks.textContent === '2'`);
    const now = await page.eval(`JSON.stringify([follows.className, kept.className])`);
    if (now !== JSON.stringify(['n2', 'changed by script'])) throw new Error(now);
  });

  await check('a field made by bind() sets the property as the user types, and an input\'s handler may take only the event', async () => {
    await page.eval(`(() => { const set = (id, v) => { const i = document.getElementById(id); i.value = v; i.dispatchEvent(new Event('input', {bubbles: true})); }; set('name', 'Ada'); set('qty', '3'); set('args', 'raw'); })()`);
    await page.until(`hello.textContent === 'Ada' && amount.textContent === '3' && typed.textContent === 'raw'`);
  });

  await check('a value the property can not take is refused: the page hears it in tetherrefused, the property stays and the field shows it again', async () => {
    await page.eval(`window.refused = []; document.addEventListener('tetherrefused', (e) => window.refused.push(e.detail.handler)); qty.type = 'text'; qty.value = 'many'; qty.dispatchEvent(new Event('input', {bubbles: true}))`);
    await page.until(`window.refused.length === 1`);
    if (await text('#amount') !== '3') throw new Error('amount ' + await text('#amount'));
    if (await page.eval(`qty.value`) !== '3') throw new Error('field ' + await page.eval(`qty.value`));
  });

  await check('Tether.reconnect() connects at once, and the connection\'s states are on <html> and in tetherconnection events', async () => {
    await page.eval(`window.states = []; document.addEventListener('tetherconnection', (e) => window.states.push(e.detail.state + ':' + e.detail.attempt))`);
    await page.eval(`window.sockets.at(-1).close()`);
    await page.until(`document.documentElement.hasAttribute('tether-offline')`);
    if (await page.eval(`(() => { const n = window.sockets.length; Tether.reconnect(); return window.sockets.length - n; })()`) !== 1) throw new Error('no connection at once');
    await page.until(`window.sockets.length === 2 && document.documentElement.hasAttribute('tether-live')`);
    const seen = await page.eval(`window.states.join() + document.documentElement.hasAttribute('tether-offline')`);
    if (seen !== 'offline:1,live:0false') throw new Error(seen);
  });

  await check('a reconnect starts over: the page is the server\'s again', async () => {
    await page.eval(`window.sockets.at(-1).close()`);
    await page.until(`window.sockets.length === 3 && ticks.textContent === '0' && note.value === '' && !more.open && mark.className === 'shown'`);
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
