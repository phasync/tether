// A minimal Chrome DevTools Protocol driver for the browser tests: headless Chrome, one page,
// evaluate JavaScript in it. No npm dependencies (Node 22's WebSocket).
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export async function launch() {
  const profile = mkdtempSync(join(tmpdir(), 'tether-chrome-'));
  const chrome = spawn('google-chrome', ['--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
  const endpoint = await new Promise((resolve, reject) => {
    let err = '';
    chrome.stderr.on('data', (d) => {
      err += d;
      const m = err.match(/DevTools listening on (ws:\/\/\S+)/);
      if (m) resolve(m[1]);
    });
    chrome.on('exit', () => reject(new Error('Chrome exited: ' + err)));
  });
  const port = new URL(endpoint).port;
  const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  const page = targets.find((t) => t.type === 'page');
  return { chrome, page: await connect(page.webSocketDebuggerUrl), port };
}

export async function newPage(port) {
  const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  return connect(target.webSocketDebuggerUrl);
}

async function connect(url) {
  const ws = new WebSocket(url);
  await new Promise((r) => (ws.onopen = r));
  let next = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (m) => {
    const msg = JSON.parse(m.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
    } else if (msg.method) {
      listeners.forEach((l) => l(msg));
    }
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++next;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
  await send('Runtime.enable');
  await send('Page.enable');
  const logs = [];
  listeners.push((m) => {
    if (m.method === 'Runtime.consoleAPICalled') logs.push(m.params.args.map((a) => a.value).join(' '));
    if (m.method === 'Runtime.exceptionThrown') logs.push('EXCEPTION ' + m.params.exceptionDetails.exception?.description);
  });
  const modifiers = (m) => (m.alt ? 1 : 0) | (m.ctrl ? 2 : 0) | (m.meta ? 4 : 0) | (m.shift ? 8 : 0);
  const key = (k) => (k.length === 1 ? { key: k, text: k, code: /[a-z]/i.test(k) ? 'Key' + k.toUpperCase() : undefined } : { key: k });
  return {
    logs,
    send,
    // Listen to a protocol event (Input.dragIntercepted, ...)
    on: (method, fn) => listeners.push((m) => m.method === method && fn(m.params)),
    // Real input, from the browser's input pipeline (Input.dispatch*): what a user's devices produce
    mouse: {
      move: (x, y, o = {}) => send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, buttons: o.buttons ?? 0, button: o.buttons ? 'left' : 'none', modifiers: modifiers(o) }),
      down: (x, y, o = {}) => send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: o.button ?? 'left', buttons: 1, clickCount: o.clickCount ?? 1, modifiers: modifiers(o) }),
      up: (x, y, o = {}) => send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: o.button ?? 'left', buttons: 0, clickCount: o.clickCount ?? 1, modifiers: modifiers(o) }),
      wheel: (x, y, deltaX, deltaY) => send('Input.dispatchMouseEvent', { type: 'mouseWheel', x, y, deltaX, deltaY }),
      async click(x, y, o = {}) {
        await this.move(x, y);
        await this.down(x, y, o);
        await this.up(x, y, o);
      },
    },
    // Touch needs Emulation.setTouchEmulationEnabled; points are [{x, y, id}]
    touch: {
      enable: () => send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }),
      start: (points) => send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: points }),
      move: (points) => send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: points }),
      end: () => send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }),
    },
    // A key press: keyDown (with text, so that it types), then keyUp; repeat: the auto-repeat of a held key
    keys: {
      down: (k, o = {}) => send('Input.dispatchKeyEvent', { type: o.noText || k.length > 1 ? 'rawKeyDown' : 'keyDown', ...key(k), modifiers: modifiers(o), autoRepeat: !!o.repeat, ...(o.code ? { code: o.code } : {}), windowsVirtualKeyCode: o.vk }),
      up: (k, o = {}) => send('Input.dispatchKeyEvent', { type: 'keyUp', ...key(k), modifiers: modifiers(o), ...(o.code ? { code: o.code } : {}), windowsVirtualKeyCode: o.vk }),
      async press(k, o = {}) {
        await this.down(k, o);
        await this.up(k, o);
      },
      // Text as typed keys
      async type(text) {
        for (const c of text) await this.press(c);
      },
    },
    // An input method composing: the composition (compositionupdate), then the committed text
    ime: {
      compose: (text) => send('Input.imeSetComposition', { text, selectionStart: text.length, selectionEnd: text.length }),
      commit: (text) => send('Input.insertText', { text }),
    },
    // The centre of an element, scrolled into view
    async centre(selector) {
      return this.eval(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); e.scrollIntoView({block: 'center'}); const r = e.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; })()`);
    },
    // Run a script in every page before its own scripts
    init: (source) => send('Page.addScriptToEvaluateOnNewDocument', { source }),
    async goto(url) {
      const loaded = new Promise((r) => listeners.push((m) => m.method === 'Page.loadEventFired' && r()));
      await send('Page.navigate', { url });
      await loaded;
    },
    async eval(expression) {
      const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
      return r.result.value;
    },
    // Wait until the expression is truthy, or throw after the timeout
    async until(expression, timeout = 3000) {
      const end = Date.now() + timeout;
      for (;;) {
        const v = await this.eval(expression);
        if (v) return v;
        if (Date.now() > end) throw new Error('Timed out waiting for: ' + expression);
        await new Promise((r) => setTimeout(r, 25));
      }
    },
    close: () => send('Page.close').catch(() => {}),
  };
}
