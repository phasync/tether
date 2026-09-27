// An App's navigation in a real browser (the demo's /app/). Usage: node tests/browser/app.mjs http://127.0.0.1:PORT
import { launch } from './cdp.mjs';

const origin = process.argv[2].replace(/\/$/, '');
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
const click = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)}).click()`);
const at = (path) => page.until(`location.pathname === ${JSON.stringify(path)}`);
// Set on the page: gone after a full page load
const mark = () => page.eval(`window.samePage = true`);
const samePage = async () => {
  if (!(await page.eval(`window.samePage === true`))) throw new Error('the page was loaded anew');
};
const call = () => page.eval(`[document.getElementById('call')?.dataset.instance, parseInt(document.getElementById('call')?.textContent.match(/\\d+/)?.[0] ?? '-1')]`);

try {
  await check('a deep link renders its page, and goes live', async () => {
    await page.goto(`${origin}/app/rooms/1`);
    await page.until(`document.getElementById('room')?.dataset.room === '1' && document.title === 'Room 1'`);
    const [, before] = await call();
    await page.until(`parseInt(document.getElementById('call').textContent.match(/\\d+/)[0]) > ${before}`);
  });

  let instance;
  await check('a link changes the page over the connection: URL, title, the new room', async () => {
    [instance] = await call();
    await mark();
    await click('#links a[href="/app/rooms/2"]');
    await at('/app/rooms/2');
    await page.until(`document.getElementById('room')?.dataset.room === '2' && document.title === 'Room 2'`);
    await samePage();
  });

  await check('a keyed child of the layout keeps its state and its coroutine across navigation', async () => {
    const [now, ticks] = await call();
    if (now !== instance) throw new Error(`the call is a new instance: ${instance} -> ${now}`);
    await page.until(`parseInt(document.getElementById('call').textContent.match(/\\d+/)[0]) > ${ticks}`);
  });

  await check('back and forward', async () => {
    await page.eval(`history.back()`);
    await at('/app/rooms/1');
    await page.until(`document.getElementById('room')?.dataset.room === '1' && document.title === 'Room 1'`);
    await page.eval(`history.forward()`);
    await at('/app/rooms/2');
    await page.until(`document.getElementById('room')?.dataset.room === '2'`);
    await samePage();
  });

  await check('a redirect below the App is followed over the connection', async () => {
    await click('#links a[href="/app/"]');
    await page.until(`document.getElementById('lobby') !== null`);
    await click('#links a[href="/app/old-room"]');
    await at('/app/rooms/2');
    await page.until(`document.getElementById('room')?.dataset.room === '2'`);
    await samePage();
  });

  await check('navigate() from a handler', async () => {
    await click('#to-room-3');
    await at('/app/rooms/3');
    await page.until(`document.getElementById('room')?.dataset.room === '3' && document.title === 'Room 3'`);
    await samePage();
  });

  await check('another root class replaces the page\'s components', async () => {
    await click('#links a[href="/app/about"]');
    await at('/app/about');
    await page.until(`document.getElementById('about') !== null && document.getElementById('call') === null && document.title === 'About'`);
    await samePage();
    await click('a[href="/app/rooms/1"]');
    await page.until(`document.getElementById('room')?.dataset.room === '1'`);
    const [now] = await call();
    if (now === instance) throw new Error('the call should be a new instance after the layout was replaced');
    await samePage();
  });

  await check('a quiet tab stays connected (the server pings; nothing was sent for 35 s)', async () => {
    await page.eval(`window.drops = 0; new MutationObserver(() => { if (document.documentElement.hasAttribute('tether-offline')) window.drops++; }).observe(document.documentElement, {attributes: true})`);
    await new Promise((r) => setTimeout(r, 35000));
    const drops = await page.eval(`window.drops`);
    if (drops !== 0) throw new Error(`${drops} disconnects`);
    await samePage();
  });

  await check('a URL that is not a page (404) is loaded by the browser', async () => {
    await click('#links a[href="/app/rooms/9"]');
    await page.until(`location.pathname === '/app/rooms/9' && document.body.textContent.includes('No such room')`, 5000);
    if (await page.eval(`window.samePage === true`)) throw new Error('expected a full page load');
  });

  await check('a link outside the App is an ordinary page load', async () => {
    await page.goto(`${origin}/app/rooms/2`);
    await page.until(`document.getElementById('call')?.textContent.match(/[1-9]/)`);
    await mark();
    await click('#links a[href="/"]');
    await at('/');
    await page.until(`document.querySelector('[tether-click=increment]') !== null`);
    if (await page.eval(`window.samePage === true`)) throw new Error('expected a full page load');
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
