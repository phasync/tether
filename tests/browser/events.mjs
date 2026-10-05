// The client events in a real browser, with real input (CDP Input.dispatch*): the demo's /events page.
// Usage: node tests/browser/events.mjs http://127.0.0.1:PORT/
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
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
const q = JSON.stringify;
const state = () => page.eval(`JSON.parse(document.querySelector('#seen').textContent)`);
const count = async (name) => (await state()).seen[name]?.n ?? 0;
const last = async (name) => (await state()).seen[name]?.d;
const waitFor = (name, n = 1, timeout = 3000) => page.until(`(JSON.parse(document.querySelector('#seen').textContent).seen[${q(name)}]?.n ?? 0) >= ${n}`, timeout);
const expect = (what, actual, wanted) => {
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) throw new Error(`${what}: ${JSON.stringify(actual)}, expected ${JSON.stringify(wanted)}`);
};
const click = async (selector, o) => page.mouse.click(...(await page.centre(selector)), o);
const KEYS = { Enter: { code: 'Enter', vk: 13 }, Escape: { code: 'Escape', vk: 27 }, '/': { code: 'Slash', vk: 191 }, k: { code: 'KeyK', vk: 75 }, a: { code: 'KeyA', vk: 65 }, A: { code: 'KeyA', vk: 65 }, x: { code: 'KeyX', vk: 88 } };
const press = (k, o = {}) => page.keys.press(k, { ...KEYS[k], ...o });

try {
  await page.goto(url + 'events');
  await page.until(`document.querySelector('#seen')`);
  // Live once an event gets through: the clicks before are dropped
  for (let i = 0; i < 40 && !(await count('ping')); i++) {
    await page.eval(`document.querySelector('#ping').click()`);
    await pause(100);
  }

  await check('pointer: enter, move and leave, with the pointer data; a left mouse button only', async () => {
    const [x, y] = await page.centre('#pad');
    await page.mouse.move(2, 2);
    await page.mouse.move(x, y);
    await waitFor('padEnter');
    await page.mouse.move(x + 5, y + 3);
    await page.until(`Math.abs((JSON.parse(document.querySelector('#seen').textContent).seen.padMove?.d.clientX ?? 0) - ${x + 5}) < 1`);
    const d = await last('padMove');
    if (d.pointerType !== 'mouse') throw new Error('data ' + JSON.stringify(d));
    await page.mouse.down(x, y, { button: 'right' });
    await page.mouse.up(x, y, { button: 'right' });
    await page.mouse.down(x, y);
    await page.mouse.up(x, y);
    await waitFor('padDown');
    await pause(100);
    expect('padDown count', await count('padDown'), 1);
    await page.mouse.move(2, 2);
    await waitFor('padLeave');
  });

  await check('mouseenter/mouseleave do not bubble: moving into a child is not a leave', async () => {
    const [x, y] = await page.centre('#hover');
    await page.mouse.move(2, 2);
    await page.mouse.move(x - 40, y);
    await waitFor('hoverIn');
    const [cx, cy] = await page.centre('#hoverchild');
    await page.mouse.move(cx, cy);
    await pause(150);
    expect('in/out', [await count('hoverIn'), await count('hoverOut')], [1, 0]);
    await page.mouse.move(2, 2);
    await waitFor('hoverOut');
  });

  await check('.delay-300: hover intent sends only when the pointer stays', async () => {
    const [x, y] = await page.centre('#intent');
    await page.mouse.move(2, 2);
    await page.mouse.move(x, y);
    await pause(100);
    await page.mouse.move(2, 2);
    await pause(400);
    expect('quick pass', await count('intent'), 0);
    await page.mouse.move(x, y);
    await waitFor('intent');
  });

  await check('.held: mouse moves only while a button is down', async () => {
    const [x, y] = await page.centre('#held');
    await page.mouse.move(x, y);
    await page.mouse.move(x + 3, y);
    await pause(200);
    expect('without a button', await count('dragSelect'), 0);
    await page.mouse.down(x, y);
    await page.mouse.move(x + 6, y, { buttons: 1 });
    await waitFor('dragSelect');
    await page.mouse.up(x, y);
  });

  await check('wheel: the first sends at once, the rest is summed; .prevent keeps the page from scrolling', async () => {
    const [x, y] = await page.centre('#pad');
    await page.mouse.move(x, y);
    const before = await page.eval('scrollY');
    for (const dy of [10, 20, 30]) await page.mouse.wheel(x, y, 0, dy);
    await waitFor('padWheel', 2);
    await pause(300);
    const d = await last('padWheel');
    expect('wheel', [await count('padWheel'), d.deltaY], [2, 50]);
    expect('scrollY', await page.eval('scrollY'), before);
  });

  await check('keys: key-enter, ctrl+key-k is an exact set, code-keya.shift, norepeat, keyup', async () => {
    await click('#keys');
    await press('Enter');
    await waitFor('enter');
    expect('enter', (await last('enter')).key, 'Enter');
    await press('k', { ctrl: true });
    await waitFor('ctrlK');
    await press('k');
    await press('k', { ctrl: true, shift: true });
    await press('A', { shift: true });
    await waitFor('shiftA');
    await press('a');
    await press('A', { shift: true, ctrl: true });
    await press('x');
    await waitFor('once');
    await page.keys.down('x', { ...KEYS.x, repeat: true });
    await page.keys.up('x', KEYS.x);
    await press('Escape');
    await waitFor('escape');
    await pause(200);
    expect('counts', [await count('enter'), await count('ctrlK'), await count('shiftA'), await count('once')], [1, 1, 1, 1]);
  });

  await check('.document.nofield: a key outside fields only', async () => {
    await press('/');
    await pause(200);
    expect('typing in a field', await count('slash'), 0);
    await page.eval('document.activeElement.blur()');
    await press('/');
    await waitFor('slash');
  });

  await check('focus and blur do not bubble but are heard; focusin/focusout bubble', async () => {
    await click('#name');
    await waitFor('focused');
    await click('#other');
    await waitFor('blurred');
    expect('related', [(await last('blurred')).name, (await last('blurred')).relatedId], ['name', 'other']);
    await waitFor('focusIn', 2);
    await waitFor('focusOut');
  });

  await check('.outside, and .stop with no handler', async () => {
    await click('#ping');
    await pause(300);
    const n = await count('outside');
    await click('#menu');
    await pause(200);
    expect('inside the element', await count('outside'), n);
    await click('#stopper');
    await pause(200);
    expect('stopped', await count('outer'), 0);
    await click('#innerbtn');
    await waitFor('inner');
    await pause(200);
    expect('the innermost binding wins', await count('outer'), 0);
    const r = await page.eval(`(() => { const r = document.querySelector('#outer').getBoundingClientRect(); return [r.x + 2, r.y + 2]; })()`);
    await page.mouse.click(...r);
    await waitFor('outer');
  });

  await check('tether-args-click wins for click; tether-args for the other events', async () => {
    const [x, y] = await page.centre('#multi');
    await page.mouse.move(x, y);
    await page.mouse.down(x, y);
    await page.mouse.up(x, y);
    await waitFor('multi');
    expect('click', (await last('multi')).n, 2);
    await page.mouse.down(x, y, { clickCount: 2 });
    await page.mouse.up(x, y, { clickCount: 2 });
    await waitFor('multi', 3);
    expect('dblclick', (await last('multi')).n, 1);
  });

  await check('tether-event: the named data comes with the default, dotted paths', async () => {
    await click('#wl', { shift: true });
    await waitFor('whitelisted');
    const d = await last('whitelisted');
    expect('data', [d.shiftKey, d['target.id'], d['currentTarget.id'], 'clientX' in d], [true, 'wl', 'wl', true]);
  });

  await check('latest: typing is coalesced and the last value wins', async () => {
    await click('#q');
    await page.keys.type('hello');
    await page.until(`JSON.parse(document.querySelector('#seen').textContent).seen.search?.d.value === 'hello'`);
    const n = await count('search');
    if (n < 1 || n >= 5) throw new Error(n + ' calls for 5 keystrokes');
  });

  await check('ordering: another binding flushes a pending debounce first', async () => {
    await click('#deb');
    await page.keys.type('ab');
    await click('#ping');
    await waitFor('debounced');
    const order = (await state()).order;
    if (order.lastIndexOf('debounced') > order.lastIndexOf('ping')) throw new Error('order ' + order);
    expect('value', (await last('debounced')).value, 'ab');
  });

  await check('.serial runs every click in order; .drop ignores clicks while one is running', async () => {
    for (let i = 0; i < 3; i++) await click('#serial');
    await waitFor('serial', 3);
    for (let i = 0; i < 3; i++) await click('#drop');
    await waitFor('dropped');
    await pause(500);
    expect('dropped', await count('dropped'), 1);
  });

  await check('composition: nothing is sent while composing, the committed text is', async () => {
    await click('#ime');
    await page.ime.compose('ni');
    await pause(300);
    expect('while composing', await count('composed'), 0);
    await page.ime.commit('你');
    await pause(200);
    expect('before the composition ends', await count('composed'), 0);
    // A real browser ends the composition itself, Chrome's Input.insertText does not
    await page.eval(`document.querySelector('#ime').dispatchEvent(new CompositionEvent('compositionend', {bubbles: true, data: '你'}))`);
    await waitFor('composed');
    expect('value', (await last('composed')).value, '\u4f60');
  });

  await check('a form: input and change send [fields, target.name]; values are strings', async () => {
    await click('input[name=a]');
    await page.keys.type('z');
    await waitFor('formInput');
    const d = await last('formInput');
    expect('input', [d.name, d.fields.a, d.fields.n], ['a', 'xz', '1']);
    await click('input[name=r][value=q]');
    await waitFor('formChange');
    const c = await last('formChange');
    expect('change', [c.name, c.fields.r], ['r', 'q']);
  });

  await check('window resize: the viewport size', async () => {
    await page.send('Emulation.setDeviceMetricsOverride', { width: 600, height: 500, deviceScaleFactor: 1, mobile: false });
    await waitFor('resized');
    expect('width', (await last('resized')).innerWidth, 600);
    await page.send('Emulation.clearDeviceMetricsOverride');
  });

  await check('elementresize: the element size', async () => {
    await page.eval(`document.querySelector('#box').style.width = '150px'`);
    await page.until(`JSON.parse(document.querySelector('#seen').textContent).seen.boxResized?.d.width === 150`);
  });

  await check('scroll and intersect: a scrolled element, an element coming into view', async () => {
    expect('not yet in view', (await last('seenBy'))?.isIntersecting ?? false, false);
    const [x, y] = await page.centre('#scroller');
    await page.mouse.wheel(x, y, 0, 300);
    await page.until(`JSON.parse(document.querySelector('#seen').textContent).seen.seenBy?.d.isIntersecting === true`);
    if ((await last('scrolled')).scrollTop <= 0) throw new Error('scrollTop ' + (await last('scrolled')).scrollTop);
  });

  await check('offline and online (the browser\'s network state)', async () => {
    await page.send('Network.enable');
    await page.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await page.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await waitFor('offline');
    await waitFor('online');
  });

  await check('visibilitychange: dispatched on the document (headless Chrome has no real hidden state to drive)', async () => {
    await page.eval(`(() => { Object.defineProperty(document, 'hidden', {value: true, configurable: true}); document.dispatchEvent(new Event('visibilitychange')); })()`);
    await waitFor('visible');
    expect('hidden', (await last('visible')).hidden, true);
  });

  await check('drag and drop: dragstart, dragover (.prevent), drop', async () => {
    await page.send('Input.setInterceptDrags', { enabled: true });
    let data = null;
    page.on('Input.dragIntercepted', (p) => (data = p.data));
    const [x, y] = await page.centre('#drag');
    await page.mouse.move(x, y);
    await page.mouse.down(x, y);
    for (let i = 1; i <= 5; i++) await page.mouse.move(x + i * 3, y + i, { buttons: 1 });
    await page.until('true');
    for (let i = 0; i < 40 && !data; i++) await pause(25);
    if (!data) throw new Error('Chrome did not intercept the drag');
    const [tx, ty] = await page.centre('#target');
    const at = { x: tx, y: ty, data, modifiers: 0 };
    await page.send('Input.dispatchDragEvent', { type: 'dragEnter', ...at });
    await page.send('Input.dispatchDragEvent', { type: 'dragOver', ...at });
    await page.send('Input.dispatchDragEvent', { type: 'drop', ...at });
    await page.mouse.up(tx, ty);
    await page.send('Input.setInterceptDrags', { enabled: false });
    await waitFor('dragStarted');
    await waitFor('dragOver');
    await waitFor('dropOn');
  });

  await check('paste: the pasted text (a synthetic ClipboardEvent: headless Chrome has no system clipboard to paste from)', async () => {
    await page.eval(`(() => { const t = new DataTransfer(); t.setData('text/plain', 'pasted text'); document.querySelector('#paste').dispatchEvent(new ClipboardEvent('paste', {clipboardData: t, bubbles: true, cancelable: true})); })()`);
    await waitFor('pasted');
    expect('text', (await last('pasted')).text, 'pasted text');
  });

  await check('touch: .prevent stops the default of touchstart; the touch points are sent', async () => {
    await page.touch.enable();
    await page.eval(`document.addEventListener('touchstart', (e) => (window.prevented = e.defaultPrevented), {passive: true})`);
    const [x, y] = await page.centre('#touch');
    await page.touch.start([{ x, y, id: 1 }]);
    await waitFor('touchStart');
    await page.touch.move([{ x: x + 20, y, id: 1 }]);
    await waitFor('touchMove');
    await page.touch.end();
    await waitFor('touchEnd');
    expect('prevented', await page.eval('window.prevented'), true);
    expect('touches', (await last('touchStart')).touches.length, 1);
    await page.send('Emulation.setTouchEmulationEnabled', { enabled: false });
  });

  await check('the mount frame tells the limits; flooding events ends the connection with 4429', async () => {
    const r = await page.eval(`new Promise((resolve) => {
      const ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + JSON.parse(document.getElementById('tether-mount').textContent).live);
      let lim = null;
      ws.onopen = () => ws.send(document.getElementById('tether-mount').textContent);
      ws.onmessage = (m) => {
        lim = JSON.parse(m.data).lim;
        for (let i = 0; i < 600; i++) ws.send('{"c":"x","m":"ping","a":[]}');
      };
      ws.onclose = (e) => resolve({ lim, code: e.code });
    })`);
    expect('limits and close code', [r.lim, r.code], [{ eps: 200, burst: 400, bytes: 524288 }, 4429]);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
