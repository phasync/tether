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
// - Frames: replies (they free the paced events), patches, then calls, then promises. Each patch
//   is a component's new HTML, morphed into its element. The inside of a child component is left
//   alone unless the child was rendered too (it is in the patch's fresh list): a parent's update
//   never disturbs a child's DOM, focus or input. An element with tether-ignore is never
//   touched once on the page.
// - Hooks: Tether.hook('Name', {mounted() {}, updated() {}, destroyed() {}, ...methods}) gives
//   every element with tether-hook="Name" an instance, while the tab is live: this.el is the
//   element, this.push(method, ...args) calls a handler of its component and resolves to what
//   it returned. The server calls methods of it with js('Name.method', ...).
// - Calls: the server's js() runs a hook method, or a function by its path from window, and
//   gets its result (a promise is awaited).
// - Navigation (an App's pages): once live, a click on a link below the App, and the browser's
//   back and forward, go over the connection; the frame that answers says the new URL and
//   title, or that the browser should load the URL itself (http and https only).
// - The connection drops (a reload, a restart, the network, a crash): hooks and paced events are
//   discarded, it reconnects, and the page's components mount again: from their props, or from
//   the URL. The delay between attempts grows, with jitter, up to 30 s, and starts over once a
//   connection has lasted 5 s. A connection the server refuses (close code 1008) reloads a page
//   of the middleware or an App (from before a deploy), and leaves a Tether::from() page static,
//   with tether-offline set.
// - A Tether::from() page has no tether-mount element: its live connection is the URL it was
//   rendered for, taken once at start, and the server's closure says what to mount.
// - Tether.debug = true (or ?tether-debug, or localStorage.tetherDebug) logs why events are
//   dropped, replaced or held back.
(() => {
  'use strict';
  const tag = document.getElementById('tether-mount');
  const mount = tag && JSON.parse(tag.textContent); // null: a Tether::from() page
  const app = !!mount && 'base' in mount; // an App's page: navigation goes over the connection
  const liveUrl = mount ? mount.live : location.pathname + location.search;
  const here = () => location.pathname + location.search;
  const hooks = {};
  const instances = new Map(); // element => hook instance, while live
  const waiting = new Map(); // reply id => {resolve, reject}: push() promises
  const acks = new Map(); // reply id => slot: paced events waiting for their acknowledgement
  let socket = null;
  let live = false;
  let backoff = 250;
  let settled = 0; // timer: the connection has stayed open long enough to start the backoff over
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

  function connect() {
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
      clearTimeout(settled);
      live = false;
      discardPaced();
      if (event.code === 1008 && mount) {
        location.reload();
        return;
      }
      document.documentElement.setAttribute('tether-offline', '');
      for (const [element, instance] of instances) {
        instances.delete(element);
        call(() => instance.destroyed?.());
      }
      for (const [id, promise] of waiting) {
        waiting.delete(id);
        promise.reject(new Error('The connection to the server closed'));
      }
      if (event.code === 1008) {
        return;
      }
      setTimeout(connect, backoff * (0.5 + Math.random() / 2));
      backoff = Math.min(backoff * 2, 30000);
    };
  }

  function receive(frame) {
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
        drain(slot);
      } else {
        others.push(reply);
      }
    }
    for (const refused of frame.refused ?? []) {
      console.error('Tether: the call was refused', refused.m);
    }
    if (frame.t === 'mount') {
      // The whole tree, from a fresh mount: every component's HTML is new
      live = true;
      document.documentElement.removeAttribute('tether-offline');
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
    for (const c of frame.calls ?? []) {
      run(c);
    }
    for (const reply of others) {
      const promise = waiting.get(reply.r);
      waiting.delete(reply.r);
      if ('e' in reply) {
        promise?.reject(new Error(reply.e));
      } else {
        promise?.resolve(reply.v);
      }
    }
  }

  // Whether a field's value belongs to the user for now: one of its bindings has an event
  // in flight or waiting, so a patch must not write what the server last knew over it
  const typing = (element) => (bindings.get(element)?.list ?? []).some((b) => b.slot.inflight > 0 || b.slot.waiting || b.slot.queue.length);

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
          const child = oldNode.getAttribute('tether-id');
          if (fresh === null || !child || child === id || fresh.has(child)) {
            return true;
          }
          // The same child, not rendered now: its DOM is its own
          return child !== newNode.getAttribute('tether-id');
        },
        beforeAttributeUpdated(name, node) {
          // false: leave the attribute (and the field's live value) as the user has it
          return !((name === 'value' || name === 'checked') && node === document.activeElement && typing(node));
        },
        afterNodeAdded(node) {
          if (node.nodeType === 1) {
            bindTree(node);
            if (node.hasAttribute('tether-hook')) {
              morphed.add(node);
            }
          }
        },
        afterNodeMorphed(oldNode) {
          if (oldNode.nodeType === 1) {
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
      Object.assign(created, { el: element, name, push: (method, ...args) => push(element, method, ...args) });
      instances.set(element, created);
      call(() => created.mounted?.());
    }
  }

  // A js() call: a hook method of the component's ("Name.method"), or a function from window
  async function run(c) {
    let result;
    try {
      const component = document.querySelector(`[tether-id="${c.c}"]`);
      const path = c.f.split('.');
      let target = window;
      if (hooks[path[0]]) {
        const element = component?.matches(`[tether-hook="${path[0]}"]`) ? component : component?.querySelector(`[tether-hook="${path[0]}"]`);
        target = instances.get(element);
        if (!target) {
          throw new Error(`Component ${c.c} has no live tether-hook="${path[0]}" element`);
        }
        path.shift();
      }
      const name = path.pop();
      for (const key of path) {
        target = target?.[key];
      }
      if (typeof target?.[name] !== 'function') {
        throw new Error(`${c.f} is not a function`);
      }
      result = JSON.stringify({ t: 'return', i: c.i, v: (await target[name](...c.a)) ?? null });
    } catch (error) {
      result = JSON.stringify({ t: 'return', i: c.i, e: String(error?.message ?? error) });
    }
    if (socket.readyState === WebSocket.OPEN) {
      socket.send(result);
    }
  }

  // Call a handler of the component element is in; resolves to what it returned
  function push(element, method, ...args) {
    const component = element.closest('[tether-id]');
    if (!component || !live || socket.readyState !== WebSocket.OPEN) {
      return Promise.reject(new Error('Not connected to the server'));
    }
    const r = nextReply + 1;
    const text = JSON.stringify({ c: component.getAttribute('tether-id'), m: method, a: args, r });
    if (!fits(text) || !take()) {
      return Promise.reject(new Error('The call was not sent: too large, or too many'));
    }
    nextReply = r;
    socket.send(text);
    return new Promise((resolve, reject) => waiting.set(r, { resolve, reject }));
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
  const RESERVED = new Set(['id', 'owner', 'hook', 'ignore', 'reload', 'keep', 'args', 'event', 'bind', 'mount', 'offline']);
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

  // The message: the component, the handler, the arguments (tether-args, then the field's
  // value) and the event's data
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
    return { c: component.getAttribute('tether-id'), m: b.handler, a: [...args, ...read], e: data };
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
  window.addEventListener('popstate', () => {
    if (app && live) {
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
    push,
    // Read the tether-* attributes of markup a hook inserted
    bind: bindTree,
    debug: /[?&]tether-debug\b/.test(location.search) || (() => {
      try {
        return !!localStorage.tetherDebug;
      } catch {
        return false;
      }
    })(),
  };

  connect();
})();
