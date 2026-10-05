// Tether::from() in a Laravel route with Blade views (playground/laravel), with real input (CDP Input.dispatch*).
// Usage: node tests/browser/laravel.mjs http://127.0.0.1:PORT/
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
const text = (selector, p = page) => p.eval(`document.querySelector(${JSON.stringify(selector)})?.textContent.trim()`);
const expect = (what, actual, wanted) => {
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) throw new Error(`${what}: ${JSON.stringify(actual)}, expected ${JSON.stringify(wanted)}`);
};
const live = (p = page) => p.until(`document.documentElement.hasAttribute('tether-live')`, 8000);
const click = async (selector, p = page) => p.mouse.click(...(await p.centre(selector)));
const enter = (p = page) => p.keys.press('Enter', { code: 'Enter', vk: 13 });
const typeInto = async (selector, value, p = page) => {
  await click(selector, p);
  await p.keys.type(value);
};

await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(...args) { super(...args); window.sockets.push(this); }
  };
})()`);

try {
  await check('an ordinary Laravel route and Blade view', async () => {
    await page.goto(new URL('/', url).href);
    expect('heading', await text('h1'), 'Tether in Laravel');
    expect('live', await page.eval(`document.documentElement.hasAttribute('tether-live')`), false);
  });

  await page.goto(new URL('/live', url).href);
  await check('the Blade layout wraps the page: title, navigation, Tether\'s script in the head, one root component', async () => {
    expect('title', await page.eval(`document.title`), 'Tether in Laravel');
    expect('nav', await page.eval(`document.querySelectorAll('nav a').length`), 3);
    expect('script', await page.eval(`document.querySelector('script[data-tether]').parentNode === document.head`), true);
    expect('root', await page.eval(`document.body.querySelectorAll(':scope > main[tether-id]').length`), 1);
    expect('children placed by the view', await page.eval(`document.querySelectorAll('[tether-id]').length`), 5);
  });

  await check('the page goes live in the same route (the upgrade is answered by Tether::from() in the controller)', async () => {
    await live();
  });

  await check('a click reaches the Blade component and the view renders again', async () => {
    await click('#counter button:nth-of-type(1)');
    await page.until(`document.getElementById('count').textContent === '1'`);
    await click('#counter button:nth-of-type(2)');
    await page.until(`document.getElementById('count').textContent === '6'`);
  });

  await check('keyed children in @foreach keep their own state when the list changes', async () => {
    await typeInto('#draft', 'Second');
    await enter();
    await page.until(`document.querySelectorAll('#todos li').length === 2`);
    await click('#todos li:nth-of-type(1) span');
    await page.until(`document.querySelector('#todos li:nth-of-type(1) span').style.textDecoration === 'line-through'`);
    await typeInto('#draft', 'Third');
    await enter();
    await page.until(`document.querySelectorAll('#todos li').length === 3`);
    expect('first stays done', await page.eval(`document.querySelector('#todos li:nth-of-type(1) span').style.textDecoration`), 'line-through');
    expect('second is not', await page.eval(`document.querySelector('#todos li:nth-of-type(2) span').style.textDecoration`), '');
    expect('the draft was cleared', await page.eval(`document.getElementById('draft').value`), '');
    await click('#todos li:nth-of-type(1) button');
    await page.until(`document.querySelectorAll('#todos li').length === 2`);
    expect('the rest', await page.eval(`[...document.querySelectorAll('#todos li span')].map((s) => s.textContent).join()`), 'Second,Third');
  });

  await check('hover: pointerenter and pointerleave, with the pointer\'s position', async () => {
    const [x, y] = await page.centre('#hover');
    await page.mouse.move(2, 2);
    await page.mouse.move(x, y);
    await page.until(`document.getElementById('hover').classList.contains('on') && document.getElementById('pointer').textContent.includes(',')`);
    await page.mouse.move(2, 2);
    await page.until(`!document.getElementById('hover').classList.contains('on')`);
  });

  await check('focus and blur', async () => {
    await click('#focus');
    await page.until(`document.getElementById('focus-state').textContent === 'focused'`);
    await click('#keys');
    await page.until(`document.getElementById('focus-state').textContent === 'blurred'`);
  });

  await check('keyboard: Enter, and Ctrl+K with the modifier', async () => {
    await enter();
    await page.until(`document.getElementById('key').textContent === 'Enter'`);
    await page.keys.press('k', { code: 'KeyK', vk: 75, ctrl: true });
    await page.until(`document.getElementById('key').textContent === 'Ctrl+k'`);
  });

  await check('after a reconnect the tab starts over from the route', async () => {
    await page.eval(`window.sockets.at(-1).close()`);
    await page.until(`window.sockets.length === 2 && document.documentElement.hasAttribute('tether-live')`);
    await page.until(`document.getElementById('count').textContent === '0' && document.querySelectorAll('#todos li').length === 1`);
  });

  await check('a room shared by two tabs, through swerve\'s publish/subscribe; the route parameter and query reach the component', async () => {
    const other = await newPage(port);
    await page.goto(new URL('/chat/lobby?name=Ada', url).href);
    await other.goto(new URL('/chat/lobby?name=Bob', url).href);
    await live();
    await live(other);
    expect('room', await text('h1'), '#lobby');
    // A message published before the tab's run() has subscribed is not seen
    await pause(300);
    await typeInto('#text', 'hello', page);
    await enter();
    await page.until(`document.getElementById('lines').textContent.includes('Ada: hello')`);
    await other.until(`document.getElementById('lines').textContent.includes('Ada: hello')`);
    await typeInto('#text', 'hi Ada', other);
    await enter(other);
    await page.until(`document.getElementById('lines').children.length === 2`);
    expect('order', await page.eval(`[...document.querySelectorAll('#lines li')].map((l) => l.textContent).join('|')`), 'Ada: hello|Bob: hi Ada');
    expect('the other room is separate', await (async () => {
      const third = await newPage(port);
      await third.goto(new URL('/chat/dev?name=Cy', url).href);
      await live(third);
      await pause(300);
      return await third.eval(`document.getElementById('lines').children.length`);
    })(), 0);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
