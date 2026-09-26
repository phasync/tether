// Tether's browser client: one WebSocket per tab. Events go up, patches come down.
//
// - Events: an element with tether-click / tether-input / tether-change / tether-submit /
//   tether-keydown names a handler method of the component it is in (the nearest tether-id).
//   The handler gets: nothing for click and keydown; the element's value for input and change;
//   the form's fields as an object for submit. `tether-keydown="send" tether-key="Enter"`
//   calls send() only for that key.
// - Patches: each is a component's new HTML, morphed into its element. The inside of a child
//   component is left alone unless the child was rendered too (it is in the patch's fresh list):
//   a parent's update never disturbs a child's DOM, focus or input.
// - The connection drops (a reload, a restart, the network): it reconnects, and the page's
//   components mount again from their props.
(() => {
  'use strict';
  const mount = JSON.parse(document.getElementById('tether-mount').textContent);
  let socket = null;
  let backoff = 250;

  function connect() {
    socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/_tether/live`);
    socket.onopen = () => {
      backoff = 250;
      document.documentElement.removeAttribute('tether-offline');
      socket.send(JSON.stringify(mount));
    };
    socket.onmessage = (message) => {
      const frame = JSON.parse(message.data);
      if (frame.t === 'mount') {
        // The whole tree, from a fresh mount: every component's HTML is new
        const root = document.querySelector('[tether-id]');
        morph(root, frame.html, null);
      } else if (frame.t === 'patch') {
        for (const patch of frame.patches) {
          const element = document.querySelector(`[tether-id="${patch.id}"]`);
          if (element) {
            morph(element, patch.html, new Set(patch.fresh));
          }
        }
      }
    };
    socket.onclose = () => {
      document.documentElement.setAttribute('tether-offline', '');
      setTimeout(connect, backoff);
      backoff = Math.min(backoff * 2, 5000);
    };
  }

  // fresh: ids rendered in this patch, or null for all
  function morph(element, html, fresh) {
    const id = element.getAttribute('tether-id');
    Idiomorph.morph(element, html, {
      morphStyle: 'outerHTML',
      callbacks: {
        beforeNodeMorphed(oldNode, newNode) {
          if (fresh === null || oldNode.nodeType !== 1) {
            return true;
          }
          const child = oldNode.getAttribute('tether-id');
          if (!child || child === id || fresh.has(child)) {
            return true;
          }
          // The same child, not rendered now: its DOM is its own
          return child !== newNode.getAttribute('tether-id');
        },
      },
    });
  }

  function send(element, event, method, args) {
    const component = element.closest('[tether-id]');
    if (!component || !socket || socket.readyState !== WebSocket.OPEN) {
      return;
    }
    socket.send(JSON.stringify({ c: component.getAttribute('tether-id'), m: method, a: args }));
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
      }
      if (type === 'submit' || type === 'click' && element.tagName === 'A') {
        event.preventDefault();
      }
      send(element, event, element.getAttribute(attribute), argsOf(element, event));
    });
  };
  listen('click', 'tether-click', () => []);
  listen('input', 'tether-input', (element) => [element.value]);
  listen('change', 'tether-change', (element) => [element.type === 'checkbox' ? element.checked : element.value]);
  listen('keydown', 'tether-keydown', () => []);
  listen('submit', 'tether-submit', (form) => [Object.fromEntries(new FormData(form))]);

  connect();
})();
