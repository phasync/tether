// The demo application in a real browser. Usage: node tests/browser/demo.mjs http://127.0.0.1:PORT/
import net from 'node:net';
import tls from 'node:tls';
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
  const { protocol, hostname, port, host } = new URL(url);
  const secure = protocol === 'https:';
  const socket = (secure ? tls : net).connect({ host: hostname, port: +port || (secure ? 443 : 80), servername: hostname }, () => socket.write(`GET /_tether/live HTTP/1.1\r\nHost: ${host}\r\nOrigin: ${origin}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n`));
  socket.once('data', (d) => { resolve(d.toString().split('\r\n')[0]); socket.destroy(); });
  socket.on('error', reject);
});

// Every WebSocket the page opens is kept in window.sockets; with window.corrupt set, the
// signature of the mount message is spoiled
await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(...args) { super(...args); window.sockets.push(this); }
    send(data) { super.send(window.corrupt ? String(data).replace(/"s":"[^"]*"/, '"s":"bad"') : data); }
  };
})()`);

try {
  await page.goto(url);
  await check('goes live: the clock gets the server time', () => page.until(`document.querySelector('strong').textContent.match(/\\d\\d:\\d\\d:\\d\\d/)`));

  await check('clicks call the handler and the component re-renders', async () => {
    for (let i = 0; i < 3; i++) await click('[tether-click=increment]');
    await page.until(`document.body.textContent.includes('Clicked 3 times')`);
  });

  await check('tether-args: the element passes arguments to the handler', async () => {
    await click('[tether-click=add]');
    await page.until(`document.body.textContent.includes('Clicked 8 times')`);
  });

  await check('tether-keydown.key-enter is Enter alone: Shift+Enter does not send', async () => {
    await type('[tether-input=type]', 'Not this');
    await pause(100);
    await page.eval(`document.querySelector('[tether-input=type]').dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', shiftKey: true, bubbles: true}))`);
    await pause(200);
    const items = await page.eval(`document.querySelectorAll('li').length`);
    if (items !== 1) throw new Error(`${items} items`);
  });

  await check('Enter that commits an IME composition does not send', async () => {
    await type('[tether-input=type]', 'Composing');
    await pause(100);
    const prevented = await page.eval(`['isComposing', 'keyCode'].map((field) => {
      const event = new KeyboardEvent('keydown', {key: 'Enter', isComposing: field === 'isComposing', keyCode: field === 'keyCode' ? 229 : 13, bubbles: true, cancelable: true});
      document.querySelector('[tether-input=type]').dispatchEvent(event);
      return event.defaultPrevented;
    })`);
    await pause(200);
    const items = await page.eval(`document.querySelectorAll('li').length`);
    if (items !== 1 || prevented.some(Boolean)) throw new Error(`${items} items, prevented ${prevented}`);
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

  await check('browser()->hook() calls a hook method and gets its result', async () => {
    await click('[tether-click=measure]');
    await page.until(`/The browser says [1-9]\\d* ms/.test(document.getElementById('measured')?.textContent)`);
  });

  await check('a call with a result that is not JSON fails with a JsException instead of hanging', async () => {
    await click('[tether-click=badResult]');
    await page.until(`document.getElementById('js-error')?.textContent.length > 0`);
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
    await page.eval(`window.lastReply = null; window.states = []; document.addEventListener('tetherconnection', (e) => window.states.push(e.detail.state + (e.detail.crashed ? ':crashed' : '')))`);
    await click('[tether-click=crash]');
    await page.until(`document.body.textContent.includes('Clicked 0 times')`, 5000);
    const states = await page.eval(`window.states.join()`);
    if (states !== 'offline:crashed,live') throw new Error('connection states ' + states);
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

  const seen = async (key) => JSON.stringify(await page.eval(`JSON.parse(document.getElementById('seen').textContent)[${JSON.stringify(key)}] ?? null`));

  await check('tether-submit: repeated names become arrays, name[] is always an array', async () => {
    await page.eval(`document.getElementById('form').requestSubmit()`);
    await page.until(`document.getElementById('seen').textContent.includes('submit')`);
    const got = await seen('submit');
    if (got !== '{"note":"hi","tag":["a","b"],"solo":["x"]}') throw new Error(got);
  });

  await check('a checkbox sends its checked state in tether-input and tether-change', async () => {
    await click('#box');
    await page.until(`document.getElementById('seen').textContent.includes('change')`);
    const got = [await seen('input'), await seen('change')];
    if (got.join() !== 'true,true') throw new Error(got.join());
    await click('#box');
    await page.until(`!JSON.parse(document.getElementById('seen').textContent).change`);
    const off = [await seen('input'), await seen('change')];
    if (off.join() !== 'false,false') throw new Error(off.join());
  });

  await check('a select multiple sends every selected value', async () => {
    await page.eval(`(() => { const s = document.getElementById('multi'); s.options[0].selected = true; s.options[2].selected = true; s.dispatchEvent(new Event('change', {bubbles: true})); })()`);
    await page.until(`document.getElementById('seen').textContent.includes('pick')`);
    const got = await seen('pick');
    if (got !== '["one","three"]') throw new Error(got);
  });

  await check('navigation to a URL that is not http(s) is refused, one to a page of the site is followed', async () => {
    await page.eval(`window.sockets.at(-1).onmessage({data: JSON.stringify({nav: {load: 'javascript:window.xss = 1'}})})`);
    await pause(300);
    if (await page.eval(`window.xss === 1`)) throw new Error('the javascript: URL ran');
    await page.eval(`window.sockets.at(-1).onmessage({data: JSON.stringify({nav: {load: '/login?name=Grace'}})})`);
    await page.until(`document.getElementById('who')?.textContent === 'Grace'`);
  });

  await check('a rejected connection (stale signature) reloads the page instead of retrying it', async () => {
    await page.until(`window.lastReply === 'hello from the server'`);
    await page.eval(`window.samePage = true; window.corrupt = true; window.sockets.at(-1).close()`);
    await page.until(`window.samePage !== true && document.getElementById('who')`, 5000);
    await page.until(`window.lastReply === 'hello from the server'`);
  });

  await check('a tab that fails right after mounting backs off between reconnects', async () => {
    await page.goto(new URL('/loop', url).href);
    await page.eval(`window.sockets.length = 0`);
    await pause(3000);
    const opens = await page.eval(`window.sockets.length`);
    if (opens > 6) throw new Error(`${opens} connections in 3 s`);
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
