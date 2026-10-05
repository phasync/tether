// Tether in Slim: the slim playground (playground/slim), Tether::from() in Slim routes, behind Slim's middleware.
// Usage: node tests/browser/slim.mjs http://127.0.0.1:PORT/
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
const text = (p, selector) => p.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent`);
const live = (p) => p.until(`document.documentElement.hasAttribute('tether-live')`, 5000);
const go = async (p, path) => {
  await p.goto(new URL(path, url).href);
  await live(p);
};
const click = async (p, selector) => p.mouse.click(...(await p.centre(selector)));
// Clicks before the live mount are dropped: click until the page shows the effect
const clickUntil = async (p, selector, done) => {
  for (let i = 0; i < 40 && !(await p.eval(done)); ++i) {
    await click(p, selector);
    await new Promise((r) => setTimeout(r, 100));
  }
  if (!(await p.eval(done))) throw new Error(`no effect: ${done}`);
};

try {
  await page.goto(new URL('/', url).href);
  await check('the home page renders on the server for the user Slim\'s middleware found, then goes live', async () => {
    if (await text(page, '#who') !== 'guest' || await text(page, '#count') !== '0') throw new Error('not rendered');
    await live(page);
  });

  await check('a counter and a todo list: events, keyed children and the closure to the parent', async () => {
    await clickUntil(page, '#inc', `document.getElementById('count').textContent === '1'`);
    await click(page, '#new');
    await page.keys.type('Buy milk');
    await page.keys.press('Enter');
    await page.until(`document.querySelectorAll('#items li').length === 2`);
    await click(page, '#items li:nth-child(2) .text');
    await page.until(`document.querySelector('#items li:nth-child(2) .text').style.textDecoration.includes('line-through')`);
    await click(page, '#items li:nth-child(1) .remove');
    await page.until(`document.querySelectorAll('#items li').length === 1 && document.querySelector('#items li .text').textContent === 'Buy milk' && document.getElementById('count').textContent === '1'`);
  });

  await check('request()->getAttribute("user") is readable live: the middleware ran for the upgrade request too', async () => {
    await page.eval(`document.cookie = 'user=ada; Path=/'`);
    await go(page, '/');
    if (await text(page, '#who') !== 'ada') throw new Error('server render: ' + await text(page, '#who'));
    await clickUntil(page, '#recheck', `document.getElementById('who-live').textContent === 'ada'`);
  });

  await check('/counter is a page of its own', async () => {
    await go(page, '/counter');
    await clickUntil(page, '#inc', `document.getElementById('count').textContent === '1'`);
  });

  await check('a chat room is shared by tabs through swerve pub/sub, as the user the middleware set', async () => {
    const other = await newPage(port);
    try {
      await go(page, '/chat/lobby');
      await go(other, '/chat/lobby');
      await page.until(`document.getElementById('chat').dataset.status === 'live'`);
      await other.until(`document.getElementById('chat').dataset.status === 'live'`);
      await click(page, '#text');
      await page.keys.type('hello');
      await page.keys.press('Enter');
      await other.until(`document.getElementById('lines').textContent === 'ada: hello'`);
      await page.until(`document.getElementById('lines').textContent === 'ada: hello' && document.getElementById('text').value === ''`);
    } finally {
      await other.close();
    }
  });

  await check('a route Slim does not know is Slim\'s 404, and a room outside the pattern too', async () => {
    const codes = await page.eval(`Promise.all(['/chat/nowhere', '/nowhere'].map((p) => fetch(p).then((r) => r.status)))`);
    if (codes.join() !== '404,404') throw new Error(codes.join());
  });

  await check('hover, focus and blur bind from the markup', async () => {
    await go(page, '/keys');
    const [x, y] = await page.centre('#box');
    await page.mouse.move(2, 2);
    for (let i = 0; i < 40 && await text(page, '#box') !== 'hovered'; ++i) {
      await page.mouse.move(x, y + (i % 2));
      await new Promise((r) => setTimeout(r, 100));
    }
    if (await text(page, '#box') !== 'hovered') throw new Error('not hovered');
    await page.mouse.move(2, 2);
    await page.until(`document.getElementById('box').textContent === 'not hovered'`);
    await click(page, '#search');
    await page.until(`document.getElementById('focused').textContent === 'search'`);
    await click(page, '#measure');
    await page.until(`document.getElementById('focused').textContent === 'nothing'`);
  });

  await check('a keyboard shortcut runs on the server, which focuses a field in the browser', async () => {
    await page.eval(`document.activeElement.blur()`);
    await page.keys.press('k', { ctrl: true, code: 'KeyK' });
    await page.until(`document.getElementById('shortcuts').textContent === '1' && document.activeElement.id === 'search'`);
  });

  await check('executeString returns a value from the browser', async () => {
    await click(page, '#measure');
    const size = await page.eval(`innerWidth + 'x' + innerHeight`);
    await page.until(`document.getElementById('size').textContent === ${JSON.stringify(size)}`);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
