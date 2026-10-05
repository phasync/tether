// Tether::from() in a mini route (the demo's /live/{id}). Usage: node tests/browser/from-mini.mjs http://127.0.0.1:PORT/
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

await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(...args) { super(...args); window.sockets.push(this); }
  };
})()`);

try {
  await page.goto(new URL('/login?name=Ada', url).href);
  await page.goto(new URL('/live/42', url).href);
  await check('renders on the server with the route parameter, the session, and goes live', async () => {
    await page.until(`document.querySelector('h1')?.textContent === 'Visit 42' && document.title === 'Visit 42'`);
    await page.until(`document.getElementById('who')?.textContent === 'Ada'`);
    await page.until(`document.querySelector('strong').textContent.match(/\\d\\d:\\d\\d:\\d\\d/) && window.sockets.length === 1`);
  });

  await check('a click works, and the hooks of the application\'s defer script run', async () => {
    await page.eval(`document.querySelector('[tether-click=increment]').click()`);
    await page.until(`document.body.textContent.includes('Clicked 1 times')`);
    await page.until(`window.lastReply === 'hello from the server'`);
  });

  await check('after a reconnect the live tab has the route\'s parameter and the session again', async () => {
    await page.eval(`window.sockets.at(-1).close()`);
    await page.until(`window.sockets.length === 2 && document.body.textContent.includes('Clicked 0 times')`);
    await page.until(`document.querySelector('h1').textContent === 'Visit 42' && document.getElementById('who').textContent === 'Ada'`);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
