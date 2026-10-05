// The connection's health in a real browser: the heartbeat and a dead connection, on the plain playground.
// Usage: node tests/browser/connection.mjs http://127.0.0.1:PORT/
import { launch } from './cdp.mjs';

const url = process.argv[2];
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
const text = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent`);

// Every WebSocket is kept in window.sockets, with the frames it sent and received; the mount frame's
// heartbeat is shortened to window.beat seconds, and a socket with .swallow set hears nothing more
await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.beat = 0.4;
  window.sockets.log = [];
  window.WebSocket = class extends Native {
    constructor(...args) {
      super(...args);
      let handler = null;
      Object.defineProperty(this, 'onmessage', { get: () => handler, set: (fn) => { handler = fn; } });
      this.addEventListener('message', (e) => {
        if (this.swallow) return;
        let data = e.data;
        const frame = JSON.parse(data);
        window.sockets.log.push('<' + frame.t);
        if (frame.t === 'mount') {
          frame.lim.ping = window.beat;
          data = JSON.stringify(frame);
        }
        handler?.({ data });
      });
      window.sockets.push(this);
    }
    send(text) {
      window.sockets.log.push('>' + (JSON.parse(text).t ?? 'event'));
      super.send(text);
    }
  };
})()`);

try {
  await page.goto(new URL('/', url).href);
  await check('a quiet connection pings, and the server answers with a pong', async () => {
    await page.until(`window.sockets.length === 1 && document.documentElement.hasAttribute('tether-live')`);
    await page.until(`window.sockets.log.filter((m) => m === '>ping').length >= 2 && window.sockets.log.filter((m) => m === '<pong').length >= 2`, 15000);
    if (await page.eval(`window.sockets.length`) !== 1) throw new Error('reconnected');
  });

  await check('a connection that hears nothing for twice the interval is dropped, and the client reconnects', async () => {
    await page.eval(`window.sockets[0].swallow = true`);
    await page.until(`window.sockets.length === 2 && document.documentElement.hasAttribute('tether-live')`, 5000);
    if (await page.eval(`window.sockets[0].readyState`) === 1) throw new Error('the dead socket is still open');
    await page.eval(`document.getElementById('inc').click()`);
    await page.until(`document.getElementById('count').textContent === '1'`);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
