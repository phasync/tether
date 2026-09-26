// The demo page in a real browser. Usage: node tests/browser/demo.mjs http://127.0.0.1:PORT/
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

try {
  await page.goto(url);
  await check('goes live: the clock gets the server time', () => page.until(`document.querySelector('strong').textContent.match(/\\d\\d:\\d\\d:\\d\\d/)`));

  await check('clicks call the handler and the component re-renders', async () => {
    for (let i = 0; i < 3; i++) await page.eval(`document.querySelector('[tether-click=increment]').click()`);
    await page.until(`document.body.textContent.includes('Clicked 3 times')`);
  });

  await check('typing and Enter add an item', async () => {
    await page.eval(`(() => { const i = document.querySelector('input'); i.focus(); i.value = 'Second'; i.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await new Promise((r) => setTimeout(r, 100));
    await page.eval(`document.querySelector('input').dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))`);
    await page.until(`document.querySelectorAll('li').length === 2`);
  });

  await check('an item keeps its own state when the list changes (keyed children)', async () => {
    await page.eval(`document.querySelector('li span').click()`);
    await page.until(`document.querySelector('li span').style.textDecoration === 'line-through'`);
    await page.eval(`(() => { const i = document.querySelector('input'); i.value = 'Third'; i.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await new Promise((r) => setTimeout(r, 100));
    await page.eval(`document.querySelector('input').dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))`);
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
    await page.eval(`(() => { const i = document.querySelector('input'); i.focus(); i.value = 'half typed'; })()`);
    const before = await page.eval(`document.querySelector('strong').textContent`);
    await page.until(`document.querySelector('strong').textContent !== ${JSON.stringify(before)}`, 2500);
    const state = await page.eval(`[document.activeElement === document.querySelector('input'), document.querySelector('input').value]`);
    if (!state[0] || state[1] !== 'half typed') throw new Error('focus/value ' + JSON.stringify(state));
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
