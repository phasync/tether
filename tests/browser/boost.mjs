// tether-boost: links of a Tether::from() page change page without a reload (playground/plain, /boost/a and /boost/b).
// Usage: node tests/browser/boost.mjs http://127.0.0.1:PORT/
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
const text = (selector) => page.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent`);
const path = () => page.eval(`location.pathname + location.search + location.hash`);
const live = (sockets) => page.until(`window.sockets.length === ${sockets} && document.documentElement.hasAttribute('tether-live')`);
const click = async (selector, o = {}) => {
  const [x, y] = await page.centre(selector);
  await page.mouse.click(x, y, o);
};
// The live mount looks like the server's render, and a click before it is dropped: click until one counts
const until = async (selector, expression) => {
  for (let i = 0; i < 40 && !(await page.eval(expression)); ++i) {
    await page.eval(`document.querySelector(${JSON.stringify(selector)}).click()`);
    await pause(100);
  }
  if (!(await page.eval(expression))) throw new Error(`${selector} did nothing`);
};
const open = async (p) => {
  await page.goto(new URL(p, url).href);
  await live(1);
  await page.eval(`window.marker = 'survives'`);
};

await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(...args) {
      super(...args);
      window.sockets.push(this);
    }
  };
})()`);

try {
  await open('/boost/a');
  await page.eval(`window.boosted = []; window.addEventListener('tetherboost', (e) => window.boosted.push(e.detail.url))`);
  await until('#inc', `count.textContent === '1'`);
  await until('#hold', `Tether.handleCount() === 1`);

  await check('a click on a link in tether-boost fetches and morphs the page: no reload, a new title and URL, scrolled to the top', async () => {
    await page.eval(`window.scrollTo(0, 300)`);
    await page.eval(`next.click()`);
    await live(2);
    if (await path() !== '/boost/b') throw new Error('url ' + await path());
    if (await page.eval(`window.marker`) !== 'survives') throw new Error('the document was reloaded');
    if (await page.eval(`document.title`) !== 'Boost b' || await text('h1') !== 'Page b') throw new Error('not page b');
    if (await page.eval(`window.scrollY`) !== 0) throw new Error('scrolled');
    if (await page.eval(`window.bodyScripts`) !== 1) throw new Error('a script of the target page ran: ' + await page.eval(`window.bodyScripts`));
    if (await page.eval(`JSON.stringify(window.boosted)`) !== JSON.stringify([new URL('/boost/b', url).href])) throw new Error('event ' + await page.eval(`JSON.stringify(window.boosted)`));
    if (await page.eval(`document.documentElement.hasAttribute('tether-navigating')`)) throw new Error('still navigating');
  });

  await check('the old connection is closed, its handles are gone, and the new page is a fresh live tab', async () => {
    if (await page.eval(`window.sockets[0].readyState`) !== 3) throw new Error('the old socket is open');
    if (await page.eval(`Tether.handleCount()`) !== 0) throw new Error('handles ' + await page.eval(`Tether.handleCount()`));
    if (await text('#count') !== '0') throw new Error('count ' + await text('#count'));
    await until('#inc', `count.textContent === '1'`);
    await pause(500);
    if (await page.eval(`window.sockets.length`) !== 2) throw new Error('the old tab reconnected');
  });

  await check('back returns to the first page, live again, and scrolled where it was', async () => {
    await page.eval(`history.back()`);
    await live(3);
    if (await path() !== '/boost/a' || await text('h1') !== 'Page a' || await page.eval(`document.title`) !== 'Boost a') throw new Error('not page a: ' + await path());
    if (await page.eval(`window.marker`) !== 'survives') throw new Error('the document was reloaded');
    if (await page.eval(`window.sockets[1].readyState`) !== 3) throw new Error('the old socket is open');
    await until('#inc', `count.textContent === '1'`);
    await page.until(`window.scrollY === 300`);
    await page.eval(`history.forward()`);
    await live(4);
    if (await path() !== '/boost/b') throw new Error('forward went to ' + await path());
    await page.eval(`history.back()`);
    await live(5);
  });

  await check('a link to the same page\'s fragment is the browser\'s', async () => {
    await click('#hash');
    await page.until(`location.hash === '#end' && window.scrollY > 500`);
    await pause(300);
    if (await page.eval(`window.sockets.length`) !== 5 || await page.eval(`window.marker`) !== 'survives') throw new Error('boosted');
    await page.eval(`window.scrollTo(0, 0)`);
  });

  await check('a click with a modifier key, or on a target=_blank link, is left to the browser', async () => {
    const before = await page.eval(`history.length`);
    await click('#next', { ctrl: true });
    await click('#next', { shift: true });
    await click('#blank');
    await pause(500);
    if (await path() !== '/boost/a#end' || await page.eval(`window.sockets.length`) !== 5 || await page.eval(`history.length`) !== before) throw new Error('boosted: ' + await path());
  });

  await check('a newer navigation cancels the one in flight, and <html> says it is navigating meanwhile', async () => {
    await page.eval(`(() => {
      const native = fetch;
      let n = 0;
      window.signals = [];
      window.fetch = async (u, o) => { window.signals.push(o.signal); if (!n++) await new Promise((r) => setTimeout(r, 800)); return native(u, o); };
    })()`);
    await page.eval(`next.click()`);
    if (!(await page.eval(`document.documentElement.hasAttribute('tether-navigating')`))) throw new Error('not navigating');
    await page.eval(`next.click()`);
    await live(6);
    await pause(900);
    const now = await page.eval(`JSON.stringify([signals.length, signals[0].aborted, signals[1].aborted, sockets.length, document.documentElement.hasAttribute('tether-navigating'), location.pathname])`);
    if (now !== JSON.stringify([2, true, false, 6, false, '/boost/b'])) throw new Error(now);
    await page.eval(`history.back()`);
    await live(7);
  });

  await check('a link without tether-boost, or inside tether-boost="off", loads the page the usual way', async () => {
    await click('#unmarked');
    await page.until(`location.search === '?unmarked'`);
    await page.until(`window.marker === undefined && document.documentElement.hasAttribute('tether-live')`);
    await open('/boost/a');
    await click('#off');
    await page.until(`location.pathname === '/boost/b' && window.marker === undefined`);
  });

  await check('a link to a page that is not a Tether page is a full load', async () => {
    await open('/boost/a');
    await click('#plain');
    await page.until(`location.pathname === '/plain.html' && window.marker === undefined && document.getElementById('plain')`);
    if (await page.eval(`document.title`) !== 'Plain') throw new Error('title');
  });

  await check('a network error falls back to a full load', async () => {
    await open('/boost/a');
    await page.eval(`window.fetch = () => Promise.reject(new TypeError('offline'))`);
    await click('#next');
    await page.until(`location.pathname === '/boost/b' && window.marker === undefined`);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
