// Tether's browser client: one WebSocket per tab. Events go up, frames come down.
//
// - Events: an element's tether-* attributes bind DOM events to handler methods of the component
//   it is in (the nearest tether-id): `tether-on-<event>[.modifier]*="handler"`, with the short
//   spellings tether-click / tether-input / tether-change / tether-submit / tether-keydown.
//   An event goes through BIND (the attributes are read when markup arrives), MATCH (which
//   binding, which modifiers), EFFECT (preventDefault), EXTRACT (the arguments and the event's
//   data), PACE (send / latest / throttle / debounce / serial / drop) and SEND. The family table
//   below is the only place where event types differ. docs/events-and-javascript.md has the
//   attribute and modifier table.
// - Frames: replies (they free the paced events), patches, then what the browser may let go of
//   (rel). Each patch is a component's new HTML, morphed into its element. The inside of a child component is left
//   alone unless the child was rendered too (it is in the patch's fresh list): a parent's update
//   never disturbs a child's DOM, focus or input. An element with tether-ignore is never
//   touched once on the page. What the page did to an element (a typed value, checked, selected,
//   open, a class a script added) survives a render unless the server's HTML for that attribute
//   changed since the last one; tether-keep="attr ..." lists attributes the server never overwrites.
// - Connection: <html> has tether-live or tether-offline (and tether-crashed after a server error), the
//   document gets tetherconnection events, Tether.reconnect() connects at once, and a refused call is
//   a console.error and a tetherrefused event. An event is sent as {c, m, a: tether-args, v: the field's
//   value}; the server joins them into the handler's parameters.
// - Hooks: Tether.hook('Name', {mounted() {}, updated() {}, destroyed() {}, ...methods}) gives
//   every element with tether-hook="Name" an instance, while the tab is live: this.el is the
//   element, this.invoke(method, ...args) calls a #[Invokable] handler of its component and
//   resolves to what it returned. The server reaches the instance with $browser->hook('Name').
// - Invoking the server: Tether.invoke(element, method, ...args) is a Promise of the handler's
//   result; it rejects with an Error on a failure or after Tether.timeout ms (Tether.invoke.within(ms, ...)
//   for another time).
// - Ops: the server's calls into the browser, each one `op` frame answered by a `ret` frame with
//   the same id: a function by its path from window, a property or method of an object it holds,
//   executeString(), a module, a hook, an element. What JSON can carry goes back as a value;
//   anything else (nodes, functions, Promises, class instances) stays here in the handle
//   table, and the server gets a reference ({$: 'h', id}) it gives back as an argument. The server
//   lets go with `rel` (references to give back), and its names and code are its own: nothing in
//   an event or an answer from this page is ever a name or code here. A Promise is not awaited
//   unless the server asks (the `await` op).
// - Navigation (an App's pages): once live, a click on a link below the App, and the browser's
//   back and forward, go over the connection; the frame that answers says the new URL and
//   title, or that the browser should load the URL itself (http and https only).
// - The connection drops (a reload, a restart, the network, a crash): hooks and paced events are
//   discarded, it reconnects, and the page's components mount again: from their props, or from
//   the URL. The delay between attempts grows, with jitter, up to 30 s, and starts over once a
//   connection has lasted 5 s. A connection the server refuses (close code 1008) reloads a page
//   of the middleware or an App (from before a deploy), and leaves a Tether::from() page static,
//   with tether-offline set.
// - Boost (a Tether::from() page): a click on a link below tether-boost fetches the page and morphs its
//   body into this one, then connects as a new tab: no continuity. Anything else is a full load.
// - A Tether::from() page has no tether-mount element: its live connection is the URL it was
//   rendered for, taken once at start, and the server's closure says what to mount.
// - Tether.debug = true (or ?tether-debug, or localStorage.tetherDebug) logs why events are
//   dropped, replaced or held back.
(() => {
  'use strict';
  const tag = document.getElementById('tether-mount');
  const mount = tag && JSON.parse(tag.textContent); // null: a Tether::from() page
  const app = !!mount && 'base' in mount; // an App's page: navigation goes over the connection
  let liveUrl = mount ? mount.live : location.pathname + location.search; // a boosted page sets it again
  const here = () => location.pathname + location.search;
  const hooks = {};
  const instances = new Map(); // element => hook instance, while live
  const mounting = new WeakMap(); // hook instance => promise of its mounted()
  const waiting = new Map(); // reply id => {resolve, reject, timer}: invoke() promises
  const handles = new Map([[0, { obj: window, refs: Infinity }], [1, { obj: document, refs: Infinity }]]); // id => {obj, refs, seen}: objects the server holds
  const handleIds = new WeakMap([[window, 0], [document, 1]]); // object => id
  let lastHandle = 1;
  const acks = new Map(); // reply id => slot: paced events waiting for their acknowledgement
  let socket = null;
  let live = false;
  let backoff = 250;
  let settled = 0; // timer: the connection has stayed open long enough to start the backoff over
  let retry = 0; // timer: the next attempt to connect
  let attempt = 0; // connections that failed since the last live one
  let nextReply = 0;
  let lim = { eps: 200, burst: 400, bytes: 524288 }; // the server's limits, from the mount frame

  const debug = (...what) => {
    if (Tether.debug) {
      console.debug('Tether:', ...what);
    }
  };
  // A throw in a hook never strands a reply or a reconnect
  const call = (fn, ...args) => {
    try {
      return fn(...args);
    } catch (error) {
      console.error('Tether hook error', error);
    }
  };

  // The connection's state, for the page's styles (tether-live, tether-offline and tether-crashed
  // on <html>) and its scripts (the tetherconnection event on document)
  function state(name, detail = {}) {
    const html = document.documentElement;
    html.toggleAttribute('tether-live', name === 'live');
    html.toggleAttribute('tether-offline', name === 'offline');
    html.toggleAttribute('tether-crashed', !!detail.crashed);
    document.dispatchEvent(new CustomEvent('tetherconnection', { detail: { state: name, attempt, ...detail } }));
  }

  // The connection is over: what lived on it goes, as when the page unloads
  function release() {
    clearTimeout(settled);
    live = false;
    rendered = new WeakMap();
    discardPaced();
    for (const [element, instance] of instances) {
      instances.delete(element);
      call(() => instance.destroyed?.());
    }
    for (const [id, promise] of waiting) {
      waiting.delete(id);
      clearTimeout(promise.timer);
      promise.reject(new Error('The connection to the server closed'));
    }
    for (const id of handles.keys()) {
      if (id > 1) {
        handleIds.delete(handles.get(id).obj);
        handles.delete(id);
      }
    }
  }

  function connect() {
    retry = 0;
    socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}${liveUrl}`);
    socket.onopen = () => {
      socket.send(JSON.stringify(!mount ? {} : app ? { u: here() } : mount));
      settled = setTimeout(() => { backoff = 250; }, 5000);
    };
    socket.onmessage = (message) => {
      try {
        receive(JSON.parse(message.data));
      } catch (error) {
        console.error('Tether: a frame failed, the tab mounts again', error);
        socket.close(4000);
      }
    };
    socket.onclose = (event) => {
      release();
      if (event.code === 1008 && mount) {
        location.reload();
        return;
      }
      const crashed = event.code === 1011;
      if (crashed) {
        console.warn('Tether: the server failed this tab; reconnecting');
      }
      if (event.code === 1008) {
        state('offline', { retryMs: null });
        return;
      }
      const retryMs = backoff * (0.5 + Math.random() / 2);
      ++attempt;
      state('offline', { crashed, retryMs: Math.round(retryMs) });
      retry = setTimeout(connect, retryMs);
      backoff = Math.min(backoff * 2, 30000);
    };
  }

  function receive(frame) {
    if (frame.t === 'op') {
      perform(frame);
      return;
    }
    const morphed = new Set();
    if (frame.nav?.load) {
      if (/^https?:$/.test(new URL(frame.nav.load, location.href).protocol)) {
        location.assign(frame.nav.load);
      } else {
        console.error('Tether: refused to load', frame.nav.load);
      }
      return;
    }
    if (frame.nav) {
      document.title = frame.nav.t;
      if (frame.nav.p) {
        history.pushState(null, '', frame.nav.u);
        window.scrollTo(0, 0);
      } else {
        history.replaceState(null, '', frame.nav.u);
      }
    }
    if (frame.t === 'mount') {
      lim = { ...lim, ...frame.lim };
      bucket = lim.burst * 0.9;
      refilled = performance.now();
    }
    // Replies first: they free the paced events, and send the waiting one, so that the patches
    // below know what the user has typed since
    const others = [];
    for (const reply of frame.replies ?? []) {
      const slot = acks.get(reply.r);
      if (slot) {
        acks.delete(reply.r);
        slot.inflight--;
        if ('e' in reply) {
          console.error('Tether: the call was refused:', reply.e);
          if (slot.b.handler === 'bound') {
            restore(slot.b.el);
          }
          slot.b.el.dispatchEvent(new CustomEvent('tetherrefused', { bubbles: true, detail: { handler: slot.b.handler, message: reply.e } }));
        }
        drain(slot);
      } else {
        others.push(reply);
      }
    }
    for (const refused of frame.refused ?? []) {
      console.error('Tether: the call was refused:', refused.e);
      document.dispatchEvent(new CustomEvent('tetherrefused', { detail: { handler: refused.m, message: refused.e } }));
    }
    if (frame.t === 'mount') {
      // The whole tree, from a fresh mount: every component's HTML is new
      live = true;
      attempt = 0;
      state('live');
      const root = document.querySelector('[tether-id]');
      morph(root, frame.html, null, morphed);
      bindTree(document.body);
    }
    for (const patch of frame.patches ?? []) {
      const element = document.querySelector(`[tether-id="${patch.id}"]`);
      if (!element) {
        throw new Error(`no element for component ${patch.id}`);
      }
      morph(element, patch.html, new Set(patch.fresh), morphed);
    }
    attachHooks(morphed);
    for (const [id, n] of frame.rel ?? []) {
      unref(id, n);
    }
    for (const reply of others) {
      const promise = waiting.get(reply.r);
      waiting.delete(reply.r);
      if (promise) {
        clearTimeout(promise.timer);
        if ('e' in reply) {
          promise.reject(Object.assign(new Error(reply.e), { name: 'TetherError' }));
        } else {
          promise.resolve(reply.v);
        }
      }
    }
  }

  // Whether a field's value belongs to the user for now: one of its bindings has an event
  // in flight or waiting, so a patch must not write what the server last knew over it
  const typing = (element) => (bindings.get(element)?.list ?? []).some((b) => b.slot.inflight > 0 || b.slot.waiting || b.slot.queue.length);

  // The attributes the server last rendered for each element (a textarea's text as its value). A
  // patch writes only what the server changed since: what the page did to the rest (typed text,
  // an open <details>, a class a script added) stays. A reconnect starts over.
  let rendered = new WeakMap();
  const attrs = (el) => {
    const found = Object.fromEntries(Array.from(el.attributes, (a) => [a.name, a.value]));
    if (el.localName === 'textarea') {
      found.value = el.defaultValue;
    }
    return found;
  };
  // A field the server refused the value of shows what it last rendered again (a radio button
  // gives the check back to its group)
  function restore(el) {
    for (const field of el.type === 'radio' ? document.getElementsByName(el.name) : [el]) {
      const was = rendered.get(field);
      if (field.localName === 'select') {
        for (const option of field.options) {
          option.selected = option.defaultSelected;
        }
      } else if (field.type === 'checkbox' || field.type === 'radio') {
        field.checked = 'checked' in was;
      } else {
        field.value = was.value ?? '';
      }
    }
  }
  let incoming = null; // the server's version of the element being morphed

  // fresh: ids rendered in this patch, or null for all. morphed collects the hook elements
  // morphed in place.
  function morph(element, html, fresh, morphed) {
    const id = element.getAttribute('tether-id');
    Idiomorph.morph(element, html, {
      morphStyle: 'outerHTML',
      callbacks: {
        beforeNodeMorphed(oldNode, newNode) {
          if (oldNode.nodeType !== 1) {
            return true;
          }
          if (oldNode.hasAttribute('tether-ignore')) {
            return false;
          }
          incoming = newNode;
          const child = oldNode.getAttribute('tether-id');
          if (fresh === null || !child || child === id || fresh.has(child)) {
            return true;
          }
          // The same child, not rendered now: its DOM is its own
          return child !== newNode.getAttribute('tether-id');
        },
        beforeAttributeUpdated(name, node) {
          // false: leave the attribute (and the field's live value) as the page has it
          if ((node.getAttribute('tether-keep') ?? '').split(/\s+/).includes(name)) {
            return false;
          }
          const was = rendered.get(node);
          if (was && (was[name] ?? null) === (attrs(incoming)[name] ?? null)) {
            return false;
          }
          return !((name === 'value' || name === 'checked') && node === document.activeElement && typing(node));
        },
        afterNodeAdded(node) {
          if (node.nodeType === 1) {
            for (const el of [node, ...node.querySelectorAll('*')]) {
              rendered.set(el, attrs(el));
            }
            bindTree(node);
            if (node.hasAttribute('tether-hook')) {
              morphed.add(node);
            }
          }
        },
        afterNodeMorphed(oldNode, newNode) {
          if (oldNode.nodeType === 1) {
            rendered.set(oldNode, attrs(newNode));
            bindElement(oldNode);
            if (oldNode.hasAttribute('tether-hook')) {
              morphed.add(oldNode);
            }
          }
        },
      },
    });
  }

  function attachHooks(morphed) {
    for (const [element, instance] of instances) {
      if (!element.isConnected || element.getAttribute('tether-hook') !== instance.name) {
        instances.delete(element);
        call(() => instance.destroyed?.());
      }
    }
    if (!live) {
      return;
    }
    for (const element of document.querySelectorAll('[tether-hook]')) {
      const instance = instances.get(element);
      if (instance) {
        if (morphed.has(element)) {
          call(() => instance.updated?.());
        }
        continue;
      }
      const name = element.getAttribute('tether-hook');
      if (!hooks[name]) {
        console.error(`Tether: no hook "${name}"; register it with Tether.hook()`);
        continue;
      }
      const created = Object.create(hooks[name]);
      Object.assign(created, { el: element, name, invoke: (method, ...args) => invoke(element, method, ...args) });
      instances.set(element, created);
      // The server's calls to the hook wait for an async mounted()
      mounting.set(created, Promise.resolve(call(() => created.mounted?.())).catch((error) => console.error('Tether hook error', error)));
    }
  }

  // ---- Ops: the server's calls into the browser -----------------------------------------------
  //
  // Names come from the server, never from the page's events or answers; these are the last
  // check for a server that took one from input
  const FORBIDDEN = new Set(['__proto__', 'constructor', 'prototype', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__']);
  const safe = (n) => {
    const text = String(n);
    if (FORBIDDEN.has(text)) {
      throw new TypeError(`"${text}" can not be used by name`);
    }
    return text;
  };
  const stale = () => Object.assign(new Error('The browser no longer holds that object'), { name: 'StaleHandle' });

  // A JSON-able value, or a reference for what JSON can not carry. $taken collects the
  // references given, to take them back if the answer is not sent.
  function pack(value, taken, seen = new Set()) {
    if (value === null || value === undefined) {
      return null;
    }
    switch (typeof value) {
      case 'boolean': case 'string': return value;
      case 'number': return Number.isFinite(value) ? value : null;
      case 'bigint': throw new TypeError('A BigInt can not be sent');
      case 'function': return reference(value, 'fn', taken);
      case 'symbol': return reference(value, 'o', taken);
    }
    if (value instanceof Promise) {
      // The server awaits it later: until then a rejection is not an unhandled one
      value.catch(() => {});
      return reference(value, 'p', taken);
    }
    const proto = Object.getPrototypeOf(value);
    const plain = Array.isArray(value) || ((proto === Object.prototype || proto === null) && Object.prototype.toString.call(value) !== '[object Module]' && !('$' in value));
    if (!plain) {
      return reference(value, 'o', taken);
    }
    if (seen.has(value)) {
      throw new TypeError('A circular structure can not be sent by value');
    }
    seen.add(value);
    const copy = Array.isArray(value) ? value.map((v) => pack(v, taken, seen)) : Object.fromEntries(Object.entries(value).map(([k, v]) => [k, pack(v, taken, seen)]));
    seen.delete(value);
    return copy;
  }

  function reference(obj, kind, taken) {
    let id = handleIds.get(obj);
    if (id === undefined) {
      id = ++lastHandle;
      handleIds.set(obj, id);
      handles.set(id, { obj, refs: 0, seen: false });
    }
    const entry = handles.get(id);
    entry.refs++;
    entry.seen ||= obj instanceof Node && obj.isConnected;
    taken.push(id);
    return { $: 'h', id, k: kind };
  }

  function unref(id, n) {
    const entry = handles.get(id);
    if (entry && id > 1 && (entry.refs -= n) <= 0) {
      handleIds.delete(entry.obj);
      handles.delete(id);
    }
  }

  // Arguments from the server: references become their objects
  function unpack(value) {
    if (Array.isArray(value)) {
      return value.map(unpack);
    }
    if (value === null || typeof value !== 'object') {
      return value;
    }
    if (value.$ === 'h') {
      return held(value.id);
    }
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, unpack(v)]));
  }

  function held(id) {
    const entry = handles.get(id);
    if (!entry || (entry.seen && entry.obj instanceof Node && !entry.obj.isConnected)) {
      throw stale();
    }
    return entry.obj;
  }

  // An object by its path from window, with the object it is read from
  function lookup(path) {
    const parts = String(path).split('.').map(safe);
    if (parts[0] === 'eval' || parts[0] === 'Function') {
      throw new TypeError(`${parts[0]} can not be called by name`);
    }
    const last = parts.pop();
    let owner = window;
    for (const part of parts) {
      owner = owner?.[part];
    }
    return [owner, owner?.[last]];
  }

  const component = (id) => document.querySelector(`[tether-id="${CSS.escape(String(id))}"]`);
  const method = (target, member) => {
    if (typeof target?.[safe(member)] !== 'function') {
      throw new TypeError(`${member} is not a function`);
    }
    return target[member];
  };

  // One op. Its value is wrapped: an async function must not flatten a Promise the server wants as an object.
  async function execute(op) {
    const args = unpack(op.a ?? []);
    switch (op.o) {
      case 'path': {
        const [owner, fn] = lookup(op.p);
        if (typeof fn !== 'function') {
          throw new TypeError(`${op.p} is not a function`);
        }
        return { v: fn.apply(owner, args) };
      }
      case 'new': {
        const [, ctor] = lookup(op.p);
        if (typeof ctor !== 'function') {
          throw new TypeError(`${op.p} is not a constructor`);
        }
        return { v: new ctor(...args) };
      }
      case 'eval':
        return { v: new Function(...op.n.map(safe), String(op.p))(...args) };
      case 'import':
        return { v: await import(new URL(String(op.p), location.href).href) };
      case 'hook': {
        const root = component(op.c);
        const selector = `[tether-hook="${CSS.escape(String(op.p))}"]`;
        const instance = instances.get(root?.matches(selector) ? root : root?.querySelector(selector));
        if (!instance) {
          throw new Error(`Component ${op.c} has no live tether-hook="${op.p}" element`);
        }
        await mounting.get(instance);
        return { v: instance };
      }
      case 'find': {
        const root = component(op.c);
        return { v: op.p === null ? root : root?.querySelector(String(op.p)) };
      }
      case 'get': return { v: held(op.h)[safe(op.p)] };
      case 'set': held(op.h)[safe(op.p)] = args[0]; return { v: null };
      case 'has': return { v: held(op.h)[safe(op.p)] != null };
      case 'del': delete held(op.h)[safe(op.p)]; return { v: null };
      case 'call': { const target = held(op.h); return { v: method(target, op.p).apply(target, args) }; }
      case 'invoke': {
        const target = held(op.h);
        if (typeof target !== 'function') {
          throw new TypeError('The object is not a function');
        }
        return { v: target(...args) };
      }
      case 'str': return { v: String(held(op.h)) };
      case 'val': return { v: JSON.parse(JSON.stringify(held(op.h)) ?? 'null') };
      case 'await': return { v: await held(op.h) };
    }
    throw new TypeError(`No such op: ${String(op.o).slice(0, 32)}`);
  }

  async function perform(op) {
    const taken = [];
    let answer;
    try {
      const { v } = await execute(op);
      answer = { t: 'ret', i: op.i, v: pack(v, taken) };
    } catch (error) {
      answer = { t: 'ret', i: op.i, e: { m: String(error?.message ?? error), n: String(error?.name ?? 'Error'), s: String(error?.stack ?? '') } };
    }
    let text = JSON.stringify(answer);
    if (!fits(text)) {
      answer = { t: 'ret', i: op.i, e: { m: 'The answer is too large to send', n: 'RangeError', s: '' } };
      text = JSON.stringify(answer);
      taken.forEach((id) => unref(id, 1));
    }
    if (live && socket.readyState === WebSocket.OPEN) {
      socket.send(text);
    } else {
      taken.forEach((id) => unref(id, 1));
    }
  }

  // ---- Invoking a handler of the component element is in; a Promise of what it returned -----------
  let timeout = 10000;
  function invoke(element, handler, ...args) {
    return within(timeout, element, handler, ...args);
  }

  function within(ms, element, handler, ...args) {
    const root = element.closest('[tether-id]');
    if (!root || !live || socket.readyState !== WebSocket.OPEN) {
      return Promise.reject(new Error('Not connected to the server'));
    }
    const r = nextReply + 1;
    const text = JSON.stringify({ c: root.getAttribute('tether-id'), m: handler, a: args, r, x: true });
    if (!fits(text) || !take()) {
      return Promise.reject(new Error('The call was not sent: too large, or too many'));
    }
    nextReply = r;
    socket.send(text);
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        waiting.delete(r);
        reject(Object.assign(new Error(`The server did not answer ${handler} in ${ms} ms`), { name: 'TimeoutError' }));
      }, ms);
      waiting.set(r, { resolve, reject, timer });
    });
  }

  // ---- The family table: the only place where event types differ --------------------------
  //
  // types      the DOM (or synthetic) event types of the family
  // payload    (event, element, args) => the data sent as `e`: Tether\Event\*EventArgs gets it
  // policy     send | latest | throttle-N | debounce-N | serial | drop
  // prevent    (event, element) => whether the default action is prevented, when the handler is not empty
  // passive    the listener is passive (until a binding says .prevent)
  // merge      (old, new) => the data of two coalesced events; none: the newer replaces
  // pair       type => the type that ends it: what .delay-N waits for
  // at         type => 'window' | 'document': where that kind is dispatched, whatever the element
  // hold       composition: events are dropped while one is under way
  // sync       (event, element) => a synchronous action, before the default is decided
  // read       (element, event) => the values appended to the handler's arguments
  // observe    (element, binding, dispatch) => a function that stops it: a synthetic event source
  // mods       the parametrised modifiers the family takes
  const MODS = ['shiftKey', 'ctrlKey', 'altKey', 'metaKey'];
  const MOUSE = ['clientX', 'clientY', 'pageX', 'pageY', 'offsetX', 'offsetY', 'screenX', 'screenY', 'button', 'buttons', 'detail', ...MODS];
  const pick = (object, keys) => Object.fromEntries(keys.map((key) => [key, object[key]]));
  const mouse = (e) => pick(e, MOUSE);
  const touches = (list) => Array.from(list ?? []).slice(0, 10).map((t) => pick(t, ['identifier', 'clientX', 'clientY', 'pageX', 'pageY', 'force']));
  const isField = (element) => /^(INPUT|SELECT|TEXTAREA)$/.test(element?.tagName);
  // The detail of a custom event, when JSON can carry it
  const plain = (detail) => {
    try {
      const text = JSON.stringify(detail ?? null);
      return text.length < 4096 ? JSON.parse(text) : null;
    } catch {
      return null;
    }
  };

  // The value of a field as a handler gets it
  const valueOf = (element) => {
    if (element.type === 'checkbox') {
      return element.checked;
    }
    if (element.multiple && element.tagName === 'SELECT') {
      return [...element.selectedOptions].map((option) => option.value);
    }
    if (element.type === 'number' || element.type === 'range') {
      return Number.isNaN(element.valueAsNumber) ? null : element.valueAsNumber;
    }
    if (element.type === 'file') {
      return [...element.files].map((file) => ({ name: file.name, size: file.size, type: file.type }));
    }
    return element.value;
  };
  // A form's fields: a repeated name, or one ending in [], gives an array; no files
  const fields = (form) => {
    const map = new Map();
    for (const [name, value] of new FormData(form)) {
      if (typeof value !== 'string') {
        continue;
      }
      const list = name.endsWith('[]');
      const key = list ? name.slice(0, -2) : name;
      map.set(key, list || map.has(key) ? [].concat(map.get(key) ?? [], [value]) : value);
    }
    return Object.fromEntries(map);
  };
  // The field an input or change binding reads: its own element, or the field the event came from
  const fieldOf = (element, e) => isField(element) || element.tagName === 'FORM' ? element : isField(e.target) ? e.target : element;
  const readField = (element, e) => {
    const field = fieldOf(element, e);
    return field.tagName === 'FORM' ? [fields(field), e.target?.name ?? ''] : [valueOf(field)];
  };
  const inputPayload = (e, element, args) => {
    const field = fieldOf(element, e);
    return field.tagName === 'FORM'
      ? { fields: args[0], name: args[1], inputType: e.inputType ?? '' }
      : { value: args[0], name: field.name ?? '', inputType: e.inputType ?? '' };
  };
  const files = (list) => Array.from(list ?? []).map((file) => ({ name: file.name, size: file.size, type: file.type }));
  const drag = (e) => ({
    ...mouse(e),
    types: [...(e.dataTransfer?.types ?? [])],
    effectAllowed: e.dataTransfer?.effectAllowed ?? '',
    dropEffect: e.dataTransfer?.dropEffect ?? '',
    files: files(e.dataTransfer?.files),
    text: e.type === 'drop' ? (e.dataTransfer?.getData('text/plain') ?? '').slice(0, 4096) : '',
  });
  const sum = (a, b) => ({ ...b, deltaX: a.deltaX + b.deltaX, deltaY: a.deltaY + b.deltaY, deltaZ: a.deltaZ + b.deltaZ });
  const pointer = (e) => ({ ...mouse(e), ...pick(e, ['pointerId', 'pointerType', 'width', 'height', 'pressure', 'tiltX', 'tiltY', 'isPrimary']) });
  const touch = (e) => ({ touches: touches(e.touches), targetTouches: touches(e.targetTouches), changedTouches: touches(e.changedTouches), ...pick(e, MODS) });

  const rows = [
    { types: ['click', 'dblclick', 'auxclick', 'contextmenu', 'mousedown', 'mouseup', 'mouseenter', 'mouseleave'], payload: mouse, policy: 'send',
      pair: { mouseenter: 'mouseleave', mousedown: 'mouseup' }, prevent: (e, el) => e.type === 'click' && el.tagName === 'A' },
    { types: ['mousemove', 'mouseover', 'mouseout'], payload: mouse, policy: 'throttle-50', pair: { mouseover: 'mouseout' } },
    { types: ['pointerdown', 'pointerup', 'pointercancel', 'pointerenter', 'pointerleave', 'pointerover', 'pointerout'], payload: pointer, policy: 'send',
      pair: { pointerenter: 'pointerleave', pointerover: 'pointerout', pointerdown: 'pointerup' } },
    { types: ['pointermove'], payload: pointer, policy: 'throttle-50' },
    { types: ['wheel'], payload: (e) => ({ ...mouse(e), ...pick(e, ['deltaX', 'deltaY', 'deltaZ', 'deltaMode']) }), policy: 'throttle-100', passive: true, merge: sum },
    { types: ['touchstart', 'touchend', 'touchcancel'], payload: touch, policy: 'send', passive: true, pair: { touchstart: 'touchend' } },
    { types: ['touchmove'], payload: touch, policy: 'throttle-50', passive: true },
    { types: ['keydown', 'keyup', 'keypress'], payload: (e) => ({ ...pick(e, ['key', 'code', 'location', 'repeat', 'isComposing']), ...pick(e, MODS) }), policy: 'send',
      norepeat: true, hold: true, prevent: (e, el, b) => !!(b.keys || b.codes) },
    { types: ['focus', 'blur', 'focusin', 'focusout'], payload: (e) => ({ relatedId: e.relatedTarget?.id ?? '', name: e.target.name ?? '' }), policy: 'send',
      pair: { focus: 'blur', focusin: 'focusout' } },
    { types: ['input'], payload: inputPayload, policy: 'latest', read: readField, hold: true },
    { types: ['change'], payload: inputPayload, policy: 'send', read: readField },
    { types: ['submit'], payload: (e, el, args) => ({ fields: args[0], submitter: e.submitter?.name ?? '' }), policy: 'send', read: (el) => [fields(el)], prevent: () => true },
    { types: ['dragstart', 'dragend', 'dragenter', 'dragleave', 'drop'], payload: drag, policy: 'send', prevent: (e) => e.type === 'drop',
      sync: (e, el) => {
        // Firefox starts a drag only when data is set, and only synchronously
        if (e.type === 'dragstart' && e.dataTransfer && !e.dataTransfer.types.length) {
          e.dataTransfer.setData('text/plain', el.id);
          e.dataTransfer.effectAllowed = 'move';
        }
      } },
    { types: ['drag', 'dragover'], payload: drag, policy: 'throttle-50' },
    { types: ['copy', 'cut', 'paste'], policy: 'send',
      payload: (e) => ({ text: e.type === 'paste' ? (e.clipboardData?.getData('text/plain') ?? '').slice(0, 65536) : '', types: [...(e.clipboardData?.types ?? [])] }) },
    { types: ['scroll'], policy: 'throttle-100', passive: true,
      payload: (e) => e.target === document
        ? { scrollX: window.scrollX, scrollY: window.scrollY }
        : pick(e.target, ['scrollTop', 'scrollLeft', 'scrollHeight', 'clientHeight', 'clientWidth']) },
    { types: ['resize'], payload: () => ({ innerWidth: window.innerWidth, innerHeight: window.innerHeight, devicePixelRatio: window.devicePixelRatio }), policy: 'throttle-100', at: { resize: 'window' } },
    { types: ['elementresize'], payload: (e) => e.detail, policy: 'throttle-100',
      observe: (el, b, dispatch) => {
        const observer = new ResizeObserver((entries) => {
          const { width, height } = entries[entries.length - 1].contentRect;
          dispatch({ width, height });
        });
        observer.observe(el);
        return () => observer.disconnect();
      } },
    { types: ['intersect'], payload: (e) => e.detail, policy: 'send', mods: ['threshold', 'margin'],
      observe: (el, b, dispatch) => {
        // The nearest scrollable ancestor is the root, else the viewport
        let root = el.parentElement;
        while (root && root !== document.documentElement && !/auto|scroll/.test(getComputedStyle(root).overflow)) {
          root = root.parentElement;
        }
        const observer = new IntersectionObserver((entries) => {
          const entry = entries[entries.length - 1];
          dispatch({ isIntersecting: entry.isIntersecting, ratio: entry.intersectionRatio });
        }, { root: root === document.documentElement ? null : root, threshold: (b.mods.threshold ?? 0) / 100, rootMargin: `${b.mods.margin ?? 0}px` });
        observer.observe(el);
        return () => observer.disconnect();
      } },
    { types: ['visibilitychange', 'online', 'offline'], policy: 'send', at: { visibilitychange: 'document', online: 'window', offline: 'window' },
      payload: (e) => e.type === 'visibilitychange' ? { hidden: document.hidden } : { online: navigator.onLine } },
    { types: ['popstate', 'hashchange', 'pageshow', 'pagehide', 'storage', 'orientationchange'], policy: 'send',
      at: { popstate: 'window', hashchange: 'window', pageshow: 'window', pagehide: 'window', storage: 'window', orientationchange: 'window' },
      payload: (e) => pick(e, ['persisted', 'newURL', 'oldURL', 'key']) },
    { types: ['timeupdate', 'progress', 'selectionchange', 'deviceorientation', 'devicemotion', 'animationiteration'], policy: 'throttle-50',
      at: { selectionchange: 'document', deviceorientation: 'window', devicemotion: 'window' }, payload: (e) => ({ detail: plain(e.detail) }) },
  ];
  const fallback = { types: [], payload: (e) => ({ detail: plain(e.detail) }), policy: 'send' };
  const rowOf = new Map(rows.flatMap((row) => row.types.map((type) => [type, row])));
  const family = (type) => rowOf.get(type) ?? fallback;

  // ---- BIND: tether-* attributes into bindings ---------------------------------------------
  const ALIASES = new Set(['click', 'input', 'change', 'submit', 'keydown']);
  const RESERVED = new Set(['id', 'owner', 'hook', 'ref', 'ignore', 'reload', 'keep', 'args', 'event', 'bind', 'mount', 'offline', 'boost']);
  const FLAGS = new Set(['prevent', 'passive', 'once', 'self', 'capture', 'window', 'document', 'outside', 'norepeat', 'stop', 'nofield', 'held',
    'shift', 'ctrl', 'alt', 'meta', 'mod', 'mouse', 'pen', 'touch', 'left', 'middle', 'right', 'latest', 'send', 'serial', 'drop']);
  const KEY_ALIASES = { space: ' ', plus: '+', minus: '-', dot: '.', comma: ',', slash: '/', equals: '=' };
  const mac = /Mac|iPhone|iPad/.test(navigator.platform);
  const bindings = new WeakMap(); // element => {sig, list}: what BIND read from its attributes
  const registry = new Map(); // "local:click", "window:resize": the listeners, one per place and type

  const policyOf = (text) => {
    const [kind, n] = text.split('-');
    return { kind, n: n === undefined ? 0 : Number(n) };
  };
  const bad = (el, name, why) => {
    console.error(`Tether: ${name}: ${why}`, el);
    return null;
  };

  // The binding an attribute makes, or null (not one, or invalid: said on the console)
  function parse(el, name, value) {
    const [head, ...mods] = name.slice(7).split('.');
    let type;
    if (head.startsWith('on-') && head.length > 3) {
      type = head.slice(3);
    } else if (ALIASES.has(head)) {
      type = head;
    } else if (RESERVED.has(head) || head.startsWith('args-')) {
      return null;
    } else if (head === 'key') {
      return bad(el, name, 'tether-key is gone: use tether-keydown.key-enter (key-<name> or code-<name> modifiers)');
    } else {
      return bad(el, name, 'unknown attribute; events are tether-on-<event>[.modifier]*');
    }
    const row = family(type);
    const flags = new Set();
    const b = { el, attr: name, value, type, row, handler: value.trim(), mods: {}, keys: null, codes: null, want: null, pointer: null, button: null, policy: null, target: 'local', active: false };
    for (const mod of mods) {
      let m;
      if (FLAGS.has(mod)) {
        flags.add(mod);
        b.mods[mod] = true;
      } else if (mod.startsWith('key-') && mod.length > 4) {
        (b.keys ??= []).push((KEY_ALIASES[mod.slice(4)] ?? mod.slice(4)).toLowerCase());
      } else if (mod.startsWith('code-') && mod.length > 5) {
        (b.codes ??= []).push(mod.slice(5).toLowerCase());
      } else if ((m = /^(throttle|debounce|delay|threshold|margin)-(\d+)$/.exec(mod))) {
        b.mods[m[1]] = Number(m[2]);
      } else {
        return bad(el, name, `invalid modifier "${mod}"`);
      }
    }
    const m = b.mods;
    const at = row.at?.[type];
    if ((b.keys || b.codes) && !row.norepeat) {
      return bad(el, name, 'key- and code- filter key events only');
    }
    if (m.norepeat && !row.norepeat) {
      return bad(el, name, 'norepeat is for key events');
    }
    if (m.passive && m.prevent) {
      return bad(el, name, 'passive and prevent contradict');
    }
    if (m.window && m.document || (m.window && at === 'document') || (m.document && at === 'window')) {
      return bad(el, name, `${type} is dispatched at ${at ?? 'one place'}: ${m.window ? 'window' : 'document'} contradicts it`);
    }
    if (m.delay !== undefined && !row.pair?.[type]) {
      return bad(el, name, `delay-N is for events that have an end (${Object.keys(row.pair ?? {}).join(', ') || 'none here'})`);
    }
    if ((m.threshold !== undefined || m.margin !== undefined) && !row.mods) {
      return bad(el, name, 'threshold-N and margin-N are for intersect');
    }
    if (m.threshold > 100) {
      return bad(el, name, 'threshold-N is a percentage, 0 to 100');
    }
    const paces = ['latest', 'send', 'serial', 'drop'].filter((flag) => flags.has(flag)).concat(['throttle', 'debounce'].filter((kind) => m[kind] !== undefined));
    if (paces.length > 1) {
      return bad(el, name, `${paces.join(' and ')} contradict`);
    }
    b.policy = paces.length ? policyOf(m.throttle !== undefined ? `throttle-${m.throttle}` : m.debounce !== undefined ? `debounce-${m.debounce}` : paces[0]) : policyOf(row.policy);
    const modKey = mac ? 'metaKey' : 'ctrlKey';
    b.want = { shiftKey: !!m.shift, ctrlKey: !!m.ctrl || (!!m.mod && modKey === 'ctrlKey'), altKey: !!m.alt, metaKey: !!m.meta || (!!m.mod && modKey === 'metaKey') };
    b.anyMod = Object.values(b.want).some(Boolean);
    b.pointer = flags.has('mouse') ? 'mouse' : flags.has('pen') ? 'pen' : flags.has('touch') ? 'touch' : null;
    b.button = flags.has('left') ? 0 : flags.has('middle') ? 1 : flags.has('right') ? 2 : null;
    b.target = m.window || m.outside ? 'window' : m.document ? 'document' : (at ?? 'local');
    b.slot = { b, inflight: 0, waiting: null, timer: 0, queue: [], pairTimer: 0, done: false };

    return b;
  }

  function bindElement(el) {
    const names = el.getAttributeNames().filter((name) => name.startsWith('tether-'));
    const previous = bindings.get(el);
    if (!names.length && !previous) {
      return;
    }
    const sig = names.map((name) => `${name}=${el.getAttribute(name)}`).join('\0');
    if (previous?.sig === sig) {
      return;
    }
    const list = [];
    for (const name of names) {
      const value = el.getAttribute(name);
      const old = previous?.list.find((o) => o.attr === name);
      if (old?.value === value) {
        list.push(old);
        continue;
      }
      const b = parse(el, name, value);
      if (b) {
        if (old) {
          // The same attribute with another value: the pace it kept (a waiting event) goes on
          b.slot = old.slot;
          b.slot.b = b;
        }
        list.push(b);
      }
    }
    for (const old of previous?.list ?? []) {
      if (!list.some((b) => b.slot === old.slot)) {
        resetSlot(old.slot);
      }
      if (!list.includes(old)) {
        deactivate(old);
      }
    }
    bindings.set(el, { sig, list });
    for (const b of list) {
      activate(b);
    }
  }

  function bindTree(root) {
    bindElement(root);
    for (const el of root.querySelectorAll('*')) {
      bindElement(el);
    }
  }

  // The listener for a binding's place and type: one each, lazily. A passive family gets a
  // passive listener until a binding says .prevent.
  function activate(b) {
    if (b.active) {
      return;
    }
    b.active = true;
    const key = `${b.target}:${b.type}`;
    const passive = !!b.row.passive && !b.mods.prevent;
    let entry = registry.get(key);
    if (!entry) {
      entry = { set: new Set(), passive, target: b.target === 'window' ? window : document, type: b.type };
      entry.fn = b.target === 'local' ? onLocal : (e) => onTargeted(entry, e);
      registry.set(key, entry);
      entry.target.addEventListener(b.type, entry.fn, { capture: true, passive });
    } else if (entry.passive && !passive) {
      entry.target.removeEventListener(b.type, entry.fn, true);
      entry.passive = false;
      entry.target.addEventListener(b.type, entry.fn, { capture: true, passive: false });
    }
    if (b.target !== 'local') {
      entry.set.add(b);
    }
    if (b.row.observe) {
      b.halt = b.row.observe(b.el, b, (detail) => {
        if (!b.el.isConnected) {
          deactivate(b);
          return;
        }
        b.el.dispatchEvent(new CustomEvent(b.type, { detail }));
      });
    }
  }

  function deactivate(b) {
    b.active = false;
    registry.get(`${b.target}:${b.type}`)?.set.delete(b);
    b.halt?.();
    b.halt = null;
  }

  // ---- MATCH, EFFECT, EXTRACT --------------------------------------------------------------
  let composing = false; // an IME composition is under way
  let heldInput = null; // the field whose input events were dropped during it

  const isFieldTarget = (node) => node?.nodeType === 1 && (isField(node) || node.isContentEditable);

  // Whether the event is the one the binding wants: its modifiers, filters and the rest
  function passes(b, e) {
    const m = b.mods;
    if (b.slot.done || !b.el.isConnected) {
      return false;
    }
    const skip = (why) => {
      debug('match', why, b.attr, e.type);
      return false;
    };
    if (b.row.hold && (composing || e.isComposing || e.keyCode === 229)) {
      if (e.type === 'input') {
        heldInput = e.target;
      }
      return skip('composing');
    }
    if (m.self && e.target !== b.el) {
      return skip('self');
    }
    if (m.outside && b.el.contains(e.target)) {
      return skip('outside');
    }
    if (m.nofield && isFieldTarget(e.target)) {
      return skip('nofield');
    }
    if (m.held && !e.buttons) {
      return skip('held');
    }
    if (m.norepeat && e.repeat) {
      return skip('norepeat');
    }
    if (b.keys || b.codes) {
      if (!(b.keys?.includes(String(e.key).toLowerCase()) || b.codes?.includes(String(e.code).toLowerCase()))) {
        return skip('key');
      }
      // With a key filter the modifiers are exactly these
      if (MODS.some((k) => e[k] !== b.want[k])) {
        return skip('modifiers');
      }
    } else if (b.anyMod && MODS.some((k) => b.want[k] && !e[k])) {
      return skip('modifiers');
    }
    if (b.pointer && (e.pointerType ?? 'mouse') !== b.pointer) {
      return skip('pointer type');
    }
    if (b.button !== null && e.button !== b.button) {
      return skip('button');
    }
    return true;
  }

  // The binding nearest the target wins; .capture bindings fire as well, outermost first; a
  // .stop ends the walk
  function onLocal(e) {
    let winner = null;
    const captured = [];
    for (let node = e.target; node; node = e.bubbles ? node.parentNode : null) {
      let stopped = false;
      for (const b of node.nodeType === 1 ? bindings.get(node)?.list ?? [] : []) {
        if (b.type !== e.type || b.target !== 'local' || !passes(b, e)) {
          continue;
        }
        if (b.mods.capture) {
          captured.push(b);
        } else if (!winner) {
          winner = b;
        } else {
          continue;
        }
        stopped ||= !!b.mods.stop;
      }
      if (stopped) {
        break;
      }
    }
    for (const b of captured.reverse()) {
      fire(b, e);
    }
    if (winner) {
      fire(winner, e);
    }
  }

  // window, document and .outside bindings, and the kinds that are dispatched at one of them
  function onTargeted(entry, e) {
    for (const b of [...entry.set]) {
      if (!b.el.isConnected) {
        entry.set.delete(b);
      } else if (passes(b, e)) {
        fire(b, e);
      }
    }
  }

  function fire(b, e) {
    const el = b.el;
    const sends = b.handler !== '';
    if (b.mods.once) {
      b.slot.done = true;
    }
    b.row.sync?.(e, el);
    // Implicit prevention only for a binding that sends: .stop="" on a link leaves it alone
    if (e.cancelable && (b.mods.prevent || (sends && !b.mods.passive && b.row.prevent?.(e, el, b)))) {
      e.preventDefault();
    }
    if (!sends) {
      return;
    }
    const msg = extract(b, e);
    if (msg) {
      b.mods.delay === undefined ? pace(b, msg) : delayed(b, msg);
    }
  }

  // The message: the component, the handler, the arguments (tether-args), the field's value
  // (it goes to the parameters tether-args left) and the event's data
  function extract(b, e) {
    const el = b.el;
    const component = el.closest('[tether-id]');
    if (!component) {
      return null;
    }
    let args = [];
    const raw = el.getAttribute(`tether-args-${b.type}`) ?? el.getAttribute('tether-args');
    if (raw !== null) {
      try {
        args = JSON.parse(raw);
        if (!Array.isArray(args)) {
          throw new Error('not an array');
        }
      } catch (error) {
        console.error('Tether: tether-args must be a JSON array', el, error);
        return null;
      }
    }
    const read = b.row.read?.(el, e) ?? [];
    const data = { type: e.type, ...b.row.payload(e, el, read) };
    const wanted = (el.getAttribute('tether-event') ?? '').split(/\s+/).filter(Boolean).slice(0, 32);
    for (const path of wanted) {
      const [first, ...rest] = path.split('.');
      let value = first === 'target' ? e.target : first === 'currentTarget' ? el : e[first];
      for (const key of rest) {
        value = value?.[key];
      }
      if (typeof value === 'string') {
        data[path] = value.slice(0, 4096);
      } else if (typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))) {
        data[path] = value;
      }
    }
    for (const key of Object.keys(data)) {
      if (data[key] === undefined || (typeof data[key] === 'number' && !Number.isFinite(data[key]))) {
        delete data[key];
      }
    }
    return { c: component.getAttribute('tether-id'), m: b.handler, a: args, v: read, e: data };
  }

  // .delay-N: the event goes on after N ms, unless the one that ends it comes first
  function delayed(b, msg) {
    const slot = b.slot;
    if (slot.pairTimer) {
      return;
    }
    const end = b.row.pair[b.type];
    const anywhere = /(up|end|cancel)$/.test(end);
    const cancel = (e) => {
      if (!anywhere && (!b.el.contains(e.target) || (e.relatedTarget && b.el.contains(e.relatedTarget)))) {
        return;
      }
      clearTimeout(slot.pairTimer);
      slot.pairTimer = 0;
      document.removeEventListener(end, cancel, true);
    };
    document.addEventListener(end, cancel, true);
    slot.pairTimer = setTimeout(() => {
      slot.pairTimer = 0;
      document.removeEventListener(end, cancel, true);
      pace(b, msg);
    }, b.mods.delay);
  }

  // An IME composition: key and input events wait for it; its end sends the final value once
  document.addEventListener('compositionstart', () => { composing = true; }, true);
  document.addEventListener('compositionend', () => {
    composing = false;
    const field = heldInput;
    heldInput = null;
    if (field?.isConnected) {
      // After this event: the field's value is final then
      setTimeout(() => field.dispatchEvent(new Event('input', { bubbles: true })), 0);
    }
  }, true);

  // ---- PACE and SEND -----------------------------------------------------------------------
  // A binding's slot holds what its policy keeps back: {inflight, waiting, timer, queue}. A slot
  // is busy while it has any of them; the busy slots are what a flush or a closed socket must
  // reach.
  const busy = new Set();
  let bucket = lim.burst * 0.9; // tokens: the server's bucket, at 90%, so an honest tab never empties it
  let refilled = performance.now();
  let warned = 0;
  const encoder = new TextEncoder();

  function take() {
    const now = performance.now();
    bucket = Math.min(lim.burst * 0.9, bucket + ((now - refilled) / 1000) * lim.eps * 0.9);
    refilled = now;
    if (bucket >= 1) {
      bucket -= 1;
      return true;
    }
    if (now - warned > 1000) {
      warned = now;
      console.warn('Tether: too many events: the surplus is dropped');
    }
    debug('send', 'bucket empty');
    return false;
  }

  function fits(text) {
    if (text.length <= lim.bytes / 3 || encoder.encode(text).length <= lim.bytes) {
      return true;
    }
    console.error('Tether: the message is over the limit of', lim.bytes, 'bytes, and is not sent');
    return false;
  }

  // Put the message on the socket. With $slot, it gets an acknowledgement id, and the slot has
  // one more in flight until the reply comes. False when it was not sent.
  function transmit(slot, msg) {
    if (!live || socket.readyState !== WebSocket.OPEN) {
      return false;
    }
    const r = nextReply + 1;
    const text = JSON.stringify(slot && slot.b.policy.kind !== 'send' ? { ...msg, r } : msg);
    if (!fits(text) || !take()) {
      return false;
    }
    if (slot && slot.b.policy.kind !== 'send') {
      nextReply = r;
      acks.set(r, slot);
      slot.inflight++;
      busy.add(slot);
    }
    debug('send', msg.m, msg.e?.type);
    socket.send(text);
    return true;
  }

  function idle(slot) {
    if (!slot.inflight && !slot.waiting && !slot.timer && !slot.queue.length) {
      busy.delete(slot);
    }
  }

  // Before a send of another binding: what is held back goes first, so events leave in the
  // order the user caused them
  function flushOthers(except) {
    for (const slot of [...busy]) {
      if (slot !== except && slot.waiting) {
        drain(slot, true);
      }
    }
  }

  // Send the waiting message when the policy allows it (or $force: the in-flight limit and the
  // wait are waived once)
  function drain(slot, force = false) {
    const { kind, n } = slot.b.policy;
    if (kind === 'serial') {
      if (slot.queue.length && !slot.inflight) {
        transmit(slot, slot.queue.shift());
      }
      return idle(slot);
    }
    if (slot.waiting && (force || (!slot.inflight && !slot.timer))) {
      clearTimeout(slot.timer);
      slot.timer = 0;
      const msg = slot.waiting;
      slot.waiting = null;
      transmit(slot, msg);
      if (kind === 'throttle') {
        slot.timer = setTimeout(() => { slot.timer = 0; drain(slot); }, n);
      }
    }
    idle(slot);
  }

  function pace(b, msg) {
    const slot = b.slot;
    const { kind, n } = b.policy;
    // What leaves now is sent after whatever other bindings hold back: the user caused that first
    const now = (target) => {
      flushOthers(slot);
      transmit(target, msg);
    };
    if (kind === 'send') {
      now(null);
    } else if (kind === 'serial') {
      if (slot.queue.length >= 64) {
        console.warn('Tether: the queue of', b.attr, 'is full: the event is dropped');
        return;
      }
      if (!slot.inflight && !slot.queue.length) {
        now(slot);
      } else {
        slot.queue.push(msg);
        busy.add(slot);
      }
    } else if (kind === 'drop') {
      if (slot.inflight) {
        debug('pace', 'dropped while in flight', b.attr);
        return;
      }
      now(slot);
    } else if (kind === 'latest') {
      if (slot.inflight) {
        coalesce(slot, msg);
      } else {
        now(slot);
      }
    } else if (kind === 'throttle') {
      if (slot.timer || slot.inflight) {
        coalesce(slot, msg);
        return;
      }
      now(slot);
      busy.add(slot);
      slot.timer = setTimeout(() => { slot.timer = 0; drain(slot); }, n);
    } else {
      // debounce: n ms after the last
      coalesce(slot, msg);
      clearTimeout(slot.timer);
      slot.timer = setTimeout(() => { slot.timer = 0; drain(slot, true); }, n);
    }
  }

  // Keep $msg as the waiting one: it replaces the one before, merged where the family says so
  function coalesce(slot, msg) {
    if (slot.waiting) {
      debug('pace', 'replaces the waiting event', slot.b.attr);
      const merge = slot.b.row.merge;
      if (merge) {
        msg = { ...msg, e: merge(slot.waiting.e, msg.e) };
      }
    }
    slot.waiting = msg;
    busy.add(slot);
  }

  // A binding that is gone: what it held back goes with it
  function resetSlot(slot) {
    clearTimeout(slot.timer);
    clearTimeout(slot.pairTimer);
    slot.timer = slot.pairTimer = 0;
    slot.waiting = null;
    slot.queue = [];
    busy.delete(slot);
  }

  // The socket closed: nothing is replayed (a form's recovery sends what the user typed)
  function discardPaced() {
    for (const slot of busy) {
      if (slot.queue.length) {
        console.warn('Tether: the connection closed with', slot.queue.length, 'events queued for', slot.b.attr);
      }
      resetSlot(slot);
      slot.inflight = 0;
    }
    busy.clear();
    acks.clear();
    nextReply = 0;
  }

  // ---- An App's links ------------------------------------------------------------------------
  const navigate = (url, push) => {
    if (take()) {
      socket.send(JSON.stringify({ t: 'navigate', u: url, p: push }));
    }
  };
  // Below the App, same window, no modifier keys; not while offline; not a link with its own click binding
  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[href]');
    if (!app || !live || !link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
      || link.hasAttribute('download') || link.hasAttribute('tether-reload') || (bindings.get(link)?.list ?? []).some((b) => b.type === 'click')
      || (link.target && link.target !== '_self')) {
      return;
    }
    const url = new URL(link.href, location.href);
    const below = url.pathname === mount.base || url.pathname.startsWith(`${mount.base}/`);
    if (url.origin !== location.origin || !below || (url.pathname + url.search === here() && url.hash)) {
      return;
    }
    event.preventDefault();
    navigate(url.pathname + url.search, true);
  });

  // ---- Boost: a Tether::from() page's links, without a reload ---------------------------------
  // The target page is fetched and morphed into this one, and opens its own live connection:
  // the old tab ends, there is no continuity. Whatever is not a Tether page is loaded the usual way.
  let shown = here(); // the URL the page on screen was rendered for
  let fetching = null; // AbortController of the navigation in flight
  async function boost(target, push) {
    fetching?.abort();
    const controller = fetching = new AbortController();
    const html = document.documentElement;
    html.setAttribute('tether-navigating', '');
    try {
      const response = await fetch(target, { headers: { Accept: 'text/html' }, credentials: 'same-origin', signal: controller.signal });
      const final = new URL(response.url);
      const doc = response.ok && final.origin === location.origin && /^text\/html\b/.test(response.headers.get('Content-Type') ?? '')
        ? new DOMParser().parseFromString(await response.text(), 'text/html')
        : null;
      if (!doc || ![...doc.scripts].some((script) => 'tether' in script.dataset) || doc.getElementById('tether-mount')) {
        throw new Error('not a Tether page');
      }
      final.hash = target.hash;
      const was = scrollY;
      release();
      clearTimeout(retry);
      socket.onopen = socket.onmessage = socket.onclose = null;
      socket.close();
      for (const name of ['tether-live', 'tether-offline', 'tether-crashed']) {
        html.removeAttribute(name);
      }
      backoff = 250;
      attempt = 0;
      document.title = doc.title;
      Idiomorph.morph(document.body, doc.body, { morphStyle: 'outerHTML' });
      if (push) {
        history.replaceState({ boost: true, y: was }, '');
        history.pushState({ boost: true }, '', final);
      }
      const y = push ? 0 : history.state.y ?? 0;
      const anchor = push && final.hash && document.getElementById(decodeURIComponent(final.hash.slice(1)));
      anchor ? anchor.scrollIntoView() : window.scrollTo(0, y);
      liveUrl = shown = here();
      boot();
      window.dispatchEvent(new CustomEvent('tetherboost', { detail: { url: final.href } }));
    } catch (error) {
      if (!controller.signal.aborted) {
        push ? location.assign(target) : location.reload();
      }
    } finally {
      if (fetching === controller) {
        fetching = null;
        html.removeAttribute('tether-navigating');
      }
    }
  }
  // tether-boost on a link or an ancestor turns it on below; tether-boost="off" turns it off again
  const boosting = (link) => { const on = link.closest('[tether-boost]'); return !!on && on.getAttribute('tether-boost') !== 'off'; };
  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[href]');
    if (mount || !link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
      || link.hasAttribute('download') || (bindings.get(link)?.list ?? []).some((b) => b.type === 'click')
      || (link.target && link.target !== '_self') || !boosting(link)) {
      return;
    }
    const url = new URL(link.href, location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || (url.pathname + url.search === here() && url.hash)) {
      return;
    }
    event.preventDefault();
    boost(url, true);
  });
  window.addEventListener('popstate', () => {
    if (!mount && history.state?.boost) {
      if (here() !== shown) {
        boost(new URL(location.href), false);
      }
    } else if (app && live) {
      navigate(here(), false);
    } else if (app) {
      location.reload();
    }
  });

  window.Tether = {
    hook(name, definition) {
      hooks[name] = definition;
      attachHooks(new Set());
    },
    invoke: Object.assign(invoke, { within }),
    get timeout() {
      return timeout;
    },
    set timeout(ms) {
      timeout = ms;
    },
    // The objects the server holds: for tests, and to see a leak
    handleCount: () => handles.size - 2,
    // Read the tether-* attributes of markup a hook inserted
    bind: bindTree,
    // Connect now, instead of when the delay has passed (or after a refusal)
    reconnect() {
      if (live || socket?.readyState === WebSocket.CONNECTING) {
        return;
      }
      clearTimeout(retry);
      connect();
    },
    debug: /[?&]tether-debug\b/.test(location.search) || (() => {
      try {
        return !!localStorage.tetherDebug;
      } catch {
        return false;
      }
    })(),
  };

  function boot() {
    for (const el of document.querySelectorAll('[tether-id], [tether-id] *')) {
      rendered.set(el, attrs(el));
    }
    connect();
  }
  boot();
})();
