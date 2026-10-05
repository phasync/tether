// The showcase playground, section by section, with real input (CDP Input.dispatch*).
// Usage: node tests/browser/showcase.mjs http://127.0.0.1:PORT/
import { launch, newPage } from './cdp.mjs';

const url = process.argv[2];
const { chrome, page, port } = await launch();
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
const expect = (what, actual, wanted) => {
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) throw new Error(`${what}: ${JSON.stringify(actual)}, expected ${JSON.stringify(wanted)}`);
};
const text = (sel) => page.eval(`document.querySelector(${JSON.stringify(sel)})?.textContent ?? null`);
const exists = (sel) => page.eval(`!!document.querySelector(${JSON.stringify(sel)})`);
const until = (expr, timeout) => page.until(expr, timeout);
const click = async (sel, o) => page.mouse.click(...(await page.centre(sel)), o);
const KEYS = { Enter: { code: 'Enter', vk: 13 }, Escape: { code: 'Escape', vk: 27 }, k: { code: 'KeyK', vk: 75 }, a: { code: 'KeyA', vk: 65 }, Shift: { code: 'ShiftLeft', vk: 16 } };
const press = (k, o = {}) => page.keys.press(k, { ...KEYS[k], ...o });
const live = () => until(`document.documentElement.hasAttribute('tether-live')`, 8000);

try {
  await page.goto(url);
  await live();
  // Events are dropped until the first round trip: click until the server has answered
  for (let i = 0; i < 40 && !(await exists('#hooklog')); i++) await pause(100);

  await check('pointer trail: the marker follows a real pointer; another tab sees it', async () => {
    const other = await newPage(port);
    await other.goto(url);
    await other.until(`document.documentElement.hasAttribute('tether-live')`, 8000);
    const [x, y] = await other.centre('#field');
    for (let i = 0; i < 6; i++) {
      await page.mouse.move(...(await page.centre('#field')));
      await page.mouse.move(...(await page.centre('#field')).map((v, k) => v + 10 + i * 3 * (k + 1)));
      await other.mouse.move(x + 5 * i, y);
      await pause(80);
    }
    await until(`!!document.querySelector('#field .marker.me')`);
    await until(`!!document.querySelector('#field .marker.other')`);
    await until(`document.querySelector('#chip').textContent.startsWith('1 other')`);
    await other.close();
    await until(`!document.querySelector('#field .marker.other')`, 5000).catch(() => {});
  });

  await check('hover intent: a quick pass shows no card; staying does; the card stays while the pointer is on it', async () => {
    const [x, y] = await page.centre('#chip');
    await page.mouse.move(2, 2);
    await page.mouse.move(x, y);
    await pause(100);
    await page.mouse.move(2, 2);
    await pause(500);
    expect('quick pass', await exists('#hovercard'), false);
    await page.mouse.move(x, y);
    await until(`!!document.querySelector('#hovercard')`);
    await page.mouse.move(...(await page.eval(`(() => { const r = document.querySelector('#hovercard').getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; })()`)));
    await pause(300);
    expect('card stays', await exists('#hovercard'), true);
    await page.mouse.move(2, 2);
    await until(`!document.querySelector('#hovercard')`);
  });

  await check('touch pad: a mouse drag is a pointer, then two real touches count as two', async () => {
    const [x, y] = await page.centre('#pad');
    await page.mouse.move(x, y);
    await page.mouse.down(x, y);
    await page.mouse.move(x + 10, y + 5, { buttons: 1 });
    await until(`document.querySelector('#touching').textContent === '1'`);
    await page.mouse.up(x + 10, y + 5);
    await until(`document.querySelector('#touching').textContent === '0'`);
    await page.touch.enable();
    await page.touch.start([{ x: x - 30, y, id: 1 }, { x: x + 30, y, id: 2 }]);
    await until(`document.querySelector('#touching').textContent === '2'`);
    expect('fingers drawn', await page.eval(`document.querySelectorAll('#pad .finger').length`), 2);
    await page.touch.move([{ x: x - 20, y: y + 10, id: 1 }, { x: x + 40, y, id: 2 }]);
    await page.touch.end();
    await until(`document.querySelector('#touching').textContent === '0'`);
    expect('most', await text('#most'), '2');
    await page.send('Emulation.setTouchEmulationEnabled', { enabled: false });
  });

  await check('keyboard: keydown, keypress and keyup with modifiers; ctrl+k opens the palette and focuses it', async () => {
    await click('#keybox');
    await press('a', { shift: true });
    await until(`document.querySelector('#keylog').textContent.includes('keyup') && document.querySelector('#keylog').textContent.includes('KeyA')`);
    expect('shift lit', await page.eval(`[...document.querySelectorAll('#keybox kbd.on')].map((k) => k.textContent)`), ['Shift']);
    await press('a');
    await until(`!document.querySelector('#keybox kbd.on')`);
    await page.eval(`document.activeElement.blur()`);
    await press('k', { ctrl: true });
    await until(`document.activeElement?.id === 'palette'`);
    await page.keys.type('hel');
    await until(`document.querySelectorAll('#palette-box li').length === 1`);
    await press('Enter');
    await until(`document.querySelector('#said').textContent.startsWith('Hello')`);
    expect('closed', await exists('#palette-box'), false);
    await press('k', { ctrl: true });
    await until(`!!document.querySelector('#palette-box')`);
    await press('Escape');
    await until(`!document.querySelector('#palette-box')`);
  });

  await check('form: focus ring from the server, validation on blur, IME-safe Enter, submit', async () => {
    await click('input[name=name]');
    await until(`document.querySelector('#field-name').classList.contains('focus')`);
    await page.keys.type('a');
    await click('input[name=email]');
    await until(`document.querySelector('#field-name').classList.contains('invalid')`);
    await until(`!document.querySelector('#field-name').classList.contains('focus') && document.querySelector('#field-email').classList.contains('focus')`);
    await page.keys.type('x');
    await click('input[name=name]');
    await until(`document.querySelector('#field-email').classList.contains('invalid')`);
    await page.keys.type('b');
    await click('input[name=email]');
    await page.eval(`document.querySelector('input[name=email]').select()`);
    await page.keys.type('ab@example.com');
    await click('input[name=tag]');
    await page.ime.compose('ni');
    await pause(300);
    expect('while composing', await text('#tags'), '');
    await page.ime.commit('你');
    await pause(200);
    await press('Enter');
    await until(`document.querySelector('#tags').textContent === '你'`);
    await click('#signup button');
    await until(`document.querySelector('#field-terms').classList.contains('invalid')`);
    await click('input[name=terms]');
    await until(`!document.querySelector('#field-terms').classList.contains('invalid')`);
    await click('#signup button');
    await until(`document.querySelector('#saved')?.textContent.includes('ab@example.com')`);
  });

  await check('drag and drop: dropping a row takes its place; the arrow buttons move a row', async () => {
    await page.send('Input.setInterceptDrags', { enabled: true });
    let data = null;
    page.on('Input.dragIntercepted', (p) => (data = p.data));
    const [x, y] = await page.centre('#item-bind .label');
    await page.mouse.move(x, y);
    await page.mouse.down(x, y);
    for (let i = 1; i <= 5; i++) await page.mouse.move(x + i * 3, y + i, { buttons: 1 });
    for (let i = 0; i < 40 && !data; i++) await pause(25);
    if (!data) throw new Error('Chrome did not intercept the drag');
    const [tx, ty] = await page.centre('#item-render .label');
    const at = { x: tx, y: ty, data, modifiers: 0 };
    await page.send('Input.dispatchDragEvent', { type: 'dragEnter', ...at });
    await page.send('Input.dispatchDragEvent', { type: 'dragOver', ...at });
    await until(`!!document.querySelector('#item-render.over')`);
    await page.send('Input.dispatchDragEvent', { type: 'drop', ...at });
    await page.mouse.up(tx, ty);
    await page.send('Input.setInterceptDrags', { enabled: false });
    await until(`document.querySelector('#order').textContent === 'write render bind morph ship'`);
    expect('DOM order', await page.eval(`[...document.querySelectorAll('#list .item')].map((i) => i.id)`), ['item-write', 'item-render', 'item-bind', 'item-morph', 'item-ship']);
    await click('#item-ship button[aria-label=Up]');
    await until(`document.querySelector('#order').textContent === 'write render bind ship morph'`);
  });

  await check('viewport: resize, scroll, offline, hidden, a resized element, a lazy section', async () => {
    await page.send('Emulation.setDeviceMetricsOverride', { width: 600, height: 500, deviceScaleFactor: 1, mobile: false });
    await until(`document.querySelector('#vp-size').textContent.startsWith('600 x 500')`);
    const [x, y] = await page.centre('#vp-size');
    await page.mouse.wheel(x, y, 0, 120);
    await until(`parseInt(document.querySelector('#vp-scroll').textContent) > 0`);
    await page.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await until(`document.querySelector('#vp-network').textContent === 'offline'`);
    await page.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await until(`document.querySelector('#vp-network').textContent === 'online'`, 8000);
    // Headless Chrome has no real hidden state to drive
    await page.eval(`(() => { Object.defineProperty(document, 'hidden', {value: true, configurable: true}); document.dispatchEvent(new Event('visibilitychange')); })()`);
    await until(`document.querySelector('#vp-visible').textContent.startsWith('hidden')`);
    const [rx, ry] = await page.eval(`(() => { const e = document.querySelector('#resizable'); e.scrollIntoView({block: 'center'}); const r = e.getBoundingClientRect(); return [r.right - 4, r.bottom - 4]; })()`);
    await page.mouse.move(rx, ry);
    await page.mouse.down(rx, ry);
    for (let i = 1; i <= 5; i++) await page.mouse.move(rx - 8 * i, ry + 6 * i, { buttons: 1 });
    await page.mouse.up(rx - 40, ry + 30);
    await until(`document.querySelector('#vp-box').textContent !== 'unknown'`);
    await page.centre('#lazy');
    await until(`document.querySelector('#lazy-text').textContent.startsWith('Loaded')`);
    await page.send('Emulation.clearDeviceMetricsOverride');
  });

  await check('interop: clipboard, localStorage kept over a reload, a server focus and scroll, Tether.invoke', async () => {
    await page.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await page.send('Browser.grantPermissions', { permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] }).catch(() => {});
    const token = await text('#token');
    await click('#copy');
    await until(`document.querySelector('#copied').textContent !== ''`);
    const copied = await text('#copied');
    if (copied !== `Copied ${token}` && copied !== 'The browser refused: NotAllowedError') throw new Error(copied);
    await click('#swatches button:nth-child(3)');
    await until(`localStorage.getItem('showcase.accent') !== null`);
    const accent = await page.eval(`localStorage.getItem('showcase.accent')`);
    await until(`getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() === ${JSON.stringify(accent)}`);
    await click('#focus-name');
    await until(`document.activeElement.id === 'name'`);
    await click('#scroll-end');
    await until(`document.querySelector('#interop-end').getBoundingClientRect().bottom <= innerHeight`);
    await click('#invoke');
    await until(`document.querySelector('#invoke-result').textContent.includes('"square":49')`);
    await page.goto(url);
    await live();
    await until(`getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() === ${JSON.stringify(accent)}`);
  });

  await check('canvas: the server paints a drag and clears it', async () => {
    const [x, y] = await page.centre('#board');
    const alpha = () => page.eval(`(() => { const c = document.querySelector('#board'); const r = c.getBoundingClientRect(); const d = c.getContext('2d').getImageData(c.width / 2, c.height / 2, 1, 1).data; return d[3]; })()`);
    expect('blank', await alpha(), 0);
    await page.mouse.move(x - 40, y);
    await page.mouse.down(x - 40, y);
    for (let i = 1; i <= 10; i++) await page.mouse.move(x - 40 + i * 8, y, { buttons: 1 });
    await page.mouse.up(x + 40, y);
    await until(`(() => { const c = document.querySelector('#board'); return c.getContext('2d').getImageData(c.width / 2, c.height / 2, 1, 1).data[3] > 0; })()`);
    await click('#clear');
    await until(`(() => { const c = document.querySelector('#board'); return c.getContext('2d').getImageData(c.width / 2, c.height / 2, 1, 1).data[3] === 0; })()`);
  });

  await check('JsObject: a fetch read through its proxies, an element found, scrolled to and measured', async () => {
    await click('#fetch');
    await until(`document.querySelector('#fetched').textContent.startsWith('200')`);
    expect('body', await page.eval(`document.querySelector('#fetched').textContent.includes('"from"')`), true);
    await click('#measure');
    await until(`/^\\d+(\\.\\d+)? x \\d+(\\.\\d+)? at /.test(document.querySelector('#rect').textContent)`);
    await until(`document.querySelector('#measured').getBoundingClientRect().bottom <= innerHeight`);
  });

  await check('hook: mounted, fed by updates, called by the server and calling it back, destroyed, mounted again', async () => {
    await until(`document.querySelector('#hooklog').textContent.includes('mounted')`);
    expect('bars', await page.eval(`document.querySelectorAll('#bars .bar').length`), 5);
    await click('#bars-add');
    await until(`document.querySelectorAll('#bars .bar').length === 6 && document.querySelector('#hooklog').textContent.includes('updated')`);
    await click('#bars .bar');
    await until(`document.querySelector('#picked').textContent === 'Bar 0 is 4'`);
    await click('#bars-flash');
    await until(`!!document.querySelector('#bars .bar.flash')`);
    await click('#bars-toggle');
    await until(`document.querySelector('#hooklog').textContent.trim().endsWith('destroyed')`);
    await click('#bars-toggle');
    await until(`document.querySelector('#hooklog').textContent.trim().endsWith('mounted') && document.querySelectorAll('#bars .bar').length === 6`);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
