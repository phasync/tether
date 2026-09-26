// Tether's browser client: one WebSocket per tab. Events go up, frames come down.
//
// - Events: an element with tether-click / tether-input / tether-change / tether-submit /
//   tether-keydown names a handler method of the component it is in (the nearest tether-id).
//   The handler gets: nothing for click and keydown; the element's value for input and change;
//   the form's fields as an object for submit. `tether-keydown="send" tether-key="Enter"`
//   calls send() only for that key.
// - Frames: patches, then calls, then replies. Each patch is a component's new HTML, morphed
//   into its element. The inside of a child component is left alone unless the child was
//   rendered too (it is in the patch's fresh list): a parent's update never disturbs a child's
//   DOM, focus or input. An element with tether-ignore is never touched once on the page.
// - Hooks: Tether.hook('Name', {mounted() {}, updated() {}, destroyed() {}, ...methods}) gives
//   every element with tether-hook="Name" an instance, while the tab is live: this.el is the
//   element, this.push(method, ...args) calls a handler of its component and resolves to what
//   it returned. The server calls methods of it with js('Name.method', ...).
// - Calls: the server's js() runs a hook method, or a function by its path from window, and
//   gets its result (a promise is awaited).
// - The connection drops (a reload, a restart, the network, a crash): hooks are destroyed, it
//   reconnects, and the page's components mount again from their props.
(() => {
  'use strict';
  const mount = JSON.parse(document.getElementById('tether-mount').textContent);
  const hooks = {};
  const instances = new Map(); // element => hook instance, while live
  const waiting = new Map(); // reply id => {resolve, reject}
  let socket = null;
  let live = false;
  let backoff = 250;
  let nextReply = 0;

  function connect() {
    socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_tether/live`);
    socket.onopen = () => socket.send(JSON.stringify(mount));
    socket.onmessage = (message) => {
      const frame = JSON.parse(message.data);
      const morphed = new Set();
      if (frame.t === 'mount') {
        // The whole tree, from a fresh mount: every component's HTML is new
        backoff = 250;
        live = true;
        document.documentElement.removeAttribute('tether-offline');
        morph(document.querySelector('[tether-id]'), frame.html, null, morphed);
      }
      for (const patch of frame.patches ?? []) {
        const element = document.querySelector(`[tether-id="${patch.id}"]`);
        if (element) {
          morph(element, patch.html, new Set(patch.fresh), morphed);
        }
      }
      attachHooks(morphed);
      for (const call of frame.calls ?? []) {
        run(call);
      }
      for (const reply of frame.replies ?? []) {
        const promise = waiting.get(reply.r);
        waiting.delete(reply.r);
        if ('e' in reply) {
          promise?.reject(new Error(reply.e));
        } else {
          promise?.resolve(reply.v);
        }
      }
    };
    socket.onclose = () => {
      live = false;
      document.documentElement.setAttribute('tether-offline', '');
      for (const [element, instance] of instances) {
        instances.delete(element);
        instance.destroyed?.();
      }
      for (const [id, promise] of waiting) {
        waiting.delete(id);
        promise.reject(new Error('The connection to the server closed'));
      }
      setTimeout(connect, backoff);
      backoff = Math.min(backoff * 2, 5000);
    };
  }

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
        afterNodeMorphed(oldNode) {
          if (oldNode.nodeType === 1 && oldNode.hasAttribute('tether-hook')) {
            morphed.add(oldNode);
          }
        },
      },
    });
  }

  function attachHooks(morphed) {
    for (const [element, instance] of instances) {
      if (!element.isConnected || element.getAttribute('tether-hook') !== instance.name) {
        instances.delete(element);
        instance.destroyed?.();
      }
    }
    if (!live) {
      return;
    }
    for (const element of document.querySelectorAll('[tether-hook]')) {
      const instance = instances.get(element);
      if (instance) {
        if (morphed.has(element)) {
          instance.updated?.();
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
      created.mounted?.();
    }
  }

  // A js() call: a hook method of the component's ("Name.method"), or a function from window
  async function run(call) {
    let result;
    try {
      const component = document.querySelector(`[tether-id="${call.c}"]`);
      const path = call.f.split('.');
      let target = window;
      if (hooks[path[0]]) {
        const element = component?.matches(`[tether-hook="${path[0]}"]`) ? component : component?.querySelector(`[tether-hook="${path[0]}"]`);
        target = instances.get(element);
        if (!target) {
          throw new Error(`Component ${call.c} has no live tether-hook="${path[0]}" element`);
        }
        path.shift();
      }
      const name = path.pop();
      for (const key of path) {
        target = target?.[key];
      }
      if (typeof target?.[name] !== 'function') {
        throw new Error(`${call.f} is not a function`);
      }
      result = { t: 'return', i: call.i, v: (await target[name](...call.a)) ?? null };
    } catch (error) {
      result = { t: 'return', i: call.i, e: String(error?.message ?? error) };
    }
    if (socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify(result));
    }
  }

  // Call a handler of the component element is in; resolves to what it returned
  function push(element, method, ...args) {
    const component = element.closest('[tether-id]');
    if (!component || !live || socket.readyState !== WebSocket.OPEN) {
      return Promise.reject(new Error('Not connected to the server'));
    }
    const r = ++nextReply;
    socket.send(JSON.stringify({ c: component.getAttribute('tether-id'), m: method, a: args, r }));
    return new Promise((resolve, reject) => waiting.set(r, { resolve, reject }));
  }

  function send(element, method, args) {
    const component = element.closest('[tether-id]');
    if (component && live && socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify({ c: component.getAttribute('tether-id'), m: method, a: args }));
    }
  }

  const listen = (type, attribute, argsOf) => {
    document.addEventListener(type, (event) => {
      const element = event.target.closest?.(`[${attribute}]`);
      if (!element) {
        return;
      }
      if (type === 'keydown') {
        const key = element.getAttribute('tether-key');
        if (key && key !== event.key) {
          return;
        }
        if (key) {
          event.preventDefault(); // Enter in a textarea sends, and adds no line
        }
      }
      if (type === 'submit' || type === 'click' && element.tagName === 'A') {
        event.preventDefault();
      }
      send(element, element.getAttribute(attribute), argsOf(element, event));
    });
  };
  listen('click', 'tether-click', () => []);
  listen('input', 'tether-input', (element) => [element.value]);
  listen('change', 'tether-change', (element) => [element.type === 'checkbox' ? element.checked : element.value]);
  listen('keydown', 'tether-keydown', () => []);
  listen('submit', 'tether-submit', (form) => [Object.fromEntries(new FormData(form))]);

  window.Tether = {
    hook(name, definition) {
      hooks[name] = definition;
      attachHooks(new Set());
    },
    push,
  };

  connect();
})();
