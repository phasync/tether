// JavaScript interop in a real browser, both directions: the demo's /interop page (Demo\Bridge).
// Usage: node tests/browser/interop.mjs http://127.0.0.1:PORT/
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
const expect = (what, actual, wanted) => {
  if (JSON.stringify(actual) !== JSON.stringify(wanted)) throw new Error(`${what}: ${JSON.stringify(actual)}, expected ${JSON.stringify(wanted)}`);
};
const out = () => page.eval(`JSON.parse(document.querySelector('#out').textContent)`);
// Click the button of a scenario, and wait for its result
const run = async (name, key = name) => {
  await page.eval(`document.querySelector('#b-${name}').click()`);
  await page.until(`${JSON.stringify(key)} in JSON.parse(document.querySelector('#out').textContent)`, 5000);
  return (await out())[key];
};
const handles = () => page.eval(`Tether.handleCount()`);
const invoke = (call, ms) => page.eval(`Tether.invoke${ms ? '.within' : ''}(${ms ? ms + ',' : ''} document.querySelector('#out'), ${call}).then((v) => ({ ok: v }), (e) => ({ error: e.name + ': ' + e.message }))`);

await page.init(`(() => {
  const Native = WebSocket;
  window.sockets = [];
  window.WebSocket = class extends Native {
    constructor(...args) { super(...args); window.sockets.push(this); }
  };
})()`);

try {
  await page.goto(url + 'interop');
  // Live when the hook has been mounted
  await page.until(`window.life?.includes('mounted')`, 5000);
  const baseline = await handles();

  await check('executeString and an element ref: the 2d context draws, with properties set and methods called', async () => {
    expect('draw', await run('draw'), true);
    expect('red', await page.eval(`[...document.getElementById('canvas').getContext('2d').getImageData(20, 20, 1, 1).data]`), [200, 0, 0, 255]);
    const blue = await page.eval(`[...document.getElementById('canvas').getContext('2d').getImageData(70, 70, 1, 1).data]`);
    if (Math.abs(blue[2] - 200) > 1 || blue[3] < 120 || blue[3] > 135) throw new Error('blue ' + JSON.stringify(blue));
  });

  await check('two Promises started and awaited later run concurrently', async () => {
    const { values, ms } = await run('pair');
    expect('values', values, [1, 2]);
    if (ms < 380 || ms > 700) throw new Error(`${ms} ms for two 400 ms waits`);
  });

  await check('a JavaScript error is a JsException with its name and message', async () => {
    const [name, message] = await run('typeError');
    if (name !== 'TypeError' || !message.includes('nope.missing')) throw new Error(name + ': ' + message);
  });

  await check('a rejected Promise, awaited on the server, throws the rejection', async () => {
    expect('rejected', await run('rejected'), ['RangeError', 'too far']);
  });

  await check('import(): a module is loaded and its exports are used', async () => {
    expect('module', await run('module'), ['hello from a module', 42]);
  });

  await check('a call that does not finish in time is a JsTimeoutException', async () => {
    expect('timeout', await run('timeout'), 'Tether\\JsTimeoutException');
  });

  await check('eval, Function and constructor can not be reached by name', async () => {
    expect('forbidden', await run('forbidden'), ['TypeError', 'TypeError', 'TypeError']);
  });

  await check('helpers through proxies: focus, select, scrollTo, storage, title, history, innerWidth', async () => {
    const h = await run('helpers');
    expect('helpers', h, { focused: 'field', selected: [0, 9], scrollTop: 50, local: 'local', session: 'session', title: 'Set by the server', hash: '#pushed', width: true });
    expect('browser', await page.eval(`[document.activeElement.id, document.title, localStorage.getItem('tether'), sessionStorage.getItem('tether'), location.hash]`), ['field', 'Set by the server', 'local', 'session', '#pushed']);
  });

  await check('the clipboard: a Promise, written and read back (or a clean NotAllowedError)', async () => {
    await page.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await page.send('Browser.grantPermissions', { permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] }).catch(() => {});
    const r = await run('clipboard');
    if (r !== 'copied by the server' && r !== 'NotAllowedError') throw new Error(JSON.stringify(r));
  });

  await check('an element that left the page is stale', async () => {
    expect('stale', await run('stale'), 'stale');
  });

  await check('objects are released when the PHP objects are gone: the handle table returns to baseline', async () => {
    const during = await run('leak');
    if (during < baseline + 20) throw new Error(`${during} handles during, baseline ${baseline}`);
    await page.until(`Tether.handleCount() <= ${baseline}`);
  });

  await check('hook lifecycle: mounted, updated by a render, destroyed with the element, mounted again', async () => {
    await page.eval(`document.querySelector('#b-ping').click()`);
    await page.until(`window.life.includes('updated')`);
    await page.eval(`document.querySelector('#b-toggleLife').click()`);
    await page.until(`window.life.at(-1) === 'destroyed'`);
    await page.eval(`document.querySelector('#b-toggleLife').click()`);
    await page.until(`window.life.at(-1) === 'mounted'`);
  });

  await check('a hook(): the call waits for an asynchronous mounted()', async () => {
    expect('slow', await run('showSlow', 'slow'), true);
  });

  await check('Tether.invoke: an #[Invokable] handler\'s return value is the Promise\'s value', async () => {
    expect('add', await invoke(`'add', 2, 3`), { ok: 5 });
  });

  await check('Tether.invoke: a failing handler rejects without the server\'s message, and the tab lives on', async () => {
    const r = await invoke(`'fail'`);
    if (!r.error || r.error.includes('secret')) throw new Error(JSON.stringify(r));
    await page.until(`document.querySelector('#caught')`);
    expect('after', await invoke(`'add', 1, 1`), { ok: 2 });
  });

  await check('Tether.invoke: a handler that is not #[Invokable] is refused', async () => {
    const r = await invoke(`'hidden'`);
    if (!r.error) throw new Error(JSON.stringify(r));
  });

  await check('Tether.invoke.within: a slow handler times out, and its late answer does no harm', async () => {
    const r = await invoke(`'pause'`, 200);
    if (!r.error?.startsWith('TimeoutError')) throw new Error(JSON.stringify(r));
    await pause(1200);
    expect('after', await invoke(`'add', 4, 4`), { ok: 8 });
    expect('slow, patient', await invoke(`'pause'`, 3000), { ok: 'late' });
  });

  await check('Tether.timeout is the default for Tether.invoke', async () => {
    await page.eval(`Tether.timeout = 200`);
    const r = await invoke(`'pause'`);
    await page.eval(`Tether.timeout = 10000`);
    if (!r.error?.startsWith('TimeoutError')) throw new Error(JSON.stringify(r));
    await pause(1000);
  });

  await check('a closed connection: a waiting invoke rejects, the handle table is emptied, and the tab reconnects', async () => {
    await page.eval(`(() => { window.lost = Tether.invoke(document.querySelector('#out'), 'pause').then(() => 'answered', (e) => e.message); window.sockets.at(-1).close(); })()`);
    expect('lost', await page.eval(`window.lost`), 'The connection to the server closed');
    expect('handles', await handles(), 0);
    await page.until(`window.life.at(-1) === 'mounted' && !document.documentElement.hasAttribute('tether-offline')`, 8000);
  });

  if (page.logs.length) results.push('browser console: ' + page.logs.join(' | '));
} finally {
  console.log(results.join('\n'));
  chrome.kill();
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
}
