// The demo application in a real browser. Usage: node tests/browser/demo.mjs http://127.0.0.1:PORT/
import net from 'node:net';
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
const type = (selector, text) => page.eval(`(() => { const i = document.querySelector(${JSON.stringify(selector)}); i.focus(); i.value = ${JSON.stringify(text)}; i.dispatchEvent(new Event('input', {bubbles: true})); })()`);
const enter = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)}).dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))`);
const click = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)}).click()`);
const pause = (ms) => new Promise((r) => setTimeout(r, ms));

// The status line of a WebSocket handshake to the live endpoint, with $origin
const handshake = (origin) => new Promise((resolve, reject) => {
  const { hostname, port, host } = new URL(url);
  const socket = net.connect(+port, hostname, () => socket.write(`GET /_tether/live HTTP/1.1\r\nHost: ${host}\r\nOrigin: ${origin}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n`));
  socket.once('data', (d) => { resolve(d.toString().split('\r\n')[0]); socket.destroy(); });
  socket.on('error', reject);
});

try {
  await page.goto(url);
  await check('goes live: the clock gets the server time', () => page.until(`document.querySelector('strong').textContent.match(/\\d\\d:\\d\\d:\\d\\d/)`));

  await check('clicks call the handler and the component re-renders', async () => {
    for (let i = 0; i < 3; i++) await click('[tether-click=increment]');
    await page.until(`document.body.textContent.includes('Clicked 3 times')`);
  });

  await check('typing and Enter add an item', async () => {
    await type('[tether-input=type]', 'Second');
    await pause(100);
    await enter('[tether-input=type]');
    await page.until(`document.querySelectorAll('li').length === 2`);
  });

  await check('an item keeps its own state when the list changes (keyed children)', async () => {
    await click('li span');
    await page.until(`document.querySelector('li span').style.textDecoration === 'line-through'`);
    await type('[tether-input=type]', 'Third');
    await pause(100);
    await enter('[tether-input=type]');
    await page.until(`document.querySelectorAll('li').length === 3`);
    const done = await page.eval(`[...document.querySelectorAll('li span')].map((s) => s.style.textDecoration === 'line-through')`);
    if (JSON.stringify(done) !== '[true,false,false]') throw new Error('done flags ' + JSON.stringify(done));
  });

  await check('removing an item through a closure prop', async () => {
    await page.eval(`document.querySelectorAll('li button')[1].click()`);
    await page.until(`document.querySelectorAll('li').length === 2`);
    const texts = await page.eval(`[...document.querySelectorAll('li span')].map((s) => s.textContent)`);
    if (JSON.stringify(texts) !== '["Try Tether","Third"]') throw new Error('items ' + JSON.stringify(texts));
  });

  await check('the clock ticking leaves focus and a half-typed value alone', async () => {
    await page.eval(`(() => { const i = document.querySelector('[tether-input=type]'); i.focus(); i.value = 'half typed'; })()`);
    const before = await page.eval(`document.querySelector('strong').textContent`);
    await page.until(`document.querySelector('strong').textContent !== ${JSON.stringify(before)}`, 2500);
    const state = await page.eval(`[document.activeElement === document.querySelector('[tether-input=type]'), document.querySelector('[tether-input=type]').value]`);
    if (!state[0] || state[1] !== 'half typed') throw new Error('focus/value ' + JSON.stringify(state));
  });

  await check('a hook mounts when the tab goes live; its push() gets the handler\'s return value', async () => {
    await page.until(`window.lastReply === 'hello from the server'`);
    await page.until(`document.getElementById('greeting').textContent.includes('Chrome')`);
  });

  await check('js() calls a hook method and gets its result', async () => {
    await click('[tether-click=measure]');
    await page.until(`/The browser says [1-9]\\d* ms/.test(document.getElementById('measured')?.textContent)`);
  });

  await check('tether-ignore: renders leave the browser\'s element alone', async () => {
    const state = await page.eval(`[document.getElementById('stopwatch').dataset.renders, document.getElementById('stopwatch').textContent]`);
    if (state[0] !== '1' || !state[1].includes('ms since the tab went live')) throw new Error('stopwatch ' + JSON.stringify(state));
  });

  await check('an error boundary shows a child\'s failure instead of it, and renders it anew on retry', async () => {
    await click('[tether-click=breakIt]');
    await page.until(`document.getElementById('panel-error')?.textContent.includes('Flaky broke')`);
    await click('[tether-click=retry]');
    await page.until(`document.getElementById('flaky') !== null`);
  });

  await check('a failure with no boundary above starts the tab over: fresh state, hooks mounted again', async () => {
    await page.eval(`window.lastReply = null`);
    await click('[tether-click=crash]');
    await page.until(`document.body.textContent.includes('Clicked 0 times')`, 5000);
    await page.until(`window.lastReply === 'hello from the server'`);
    await click('[tether-click=increment]');
    await page.until(`document.body.textContent.includes('Clicked 1 times')`);
  });

  await check('the session is the tab\'s, on the first render and live', async () => {
    await page.goto(new URL('/login?name=Ada', url).href);
    await page.until(`document.getElementById('who')?.textContent === 'Ada'`);
    await page.until(`window.lastReply === 'hello from the server'`); // live now: mounted again
    const who = await page.eval(`document.getElementById('who')?.textContent`);
    if (who !== 'Ada') throw new Error('live mount sees ' + JSON.stringify(who));
  });

  await check('a page from another site can not open a live connection', async () => {
    const own = await handshake(new URL(url).origin);
    const other = await handshake('https://evil.example');
    if (!own.includes('101') || !other.includes('403')) throw new Error(JSON.stringify([own, other]));
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
