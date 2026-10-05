# Attributes and modifiers

Everything Tether reads from your HTML, and everything it sets on it. Tether reads `tether-*`
attributes only; other attributes are yours. An unknown `tether-*` attribute, an invalid
modifier or a contradiction (`passive` with `prevent`) is a `console.error` in the browser.

## Attributes

| Attribute | On | Meaning | Details |
|---|---|---|---|
| `tether-on-<event>[.modifier]*="handler"` | any element | call a public method of the element's component when the DOM event happens; every DOM event type works, and so do `intersect` and `elementresize` | [Events](events-and-javascript.md#events) |
| `tether-click` `tether-input` `tether-change` `tether-submit` `tether-keydown` | any element | short for `tether-on-click` and so on; take the same modifiers: `tether-keydown.key-enter="add"` | [Events](events-and-javascript.md#events) |
| `tether-args="[1, \"x\"]"` | an element with a binding | JSON array passed to the handler before the field's value and the event | [Events](events-and-javascript.md#events) |
| `tether-args-<event>` | the same | like `tether-args`, for that event only; wins over it | |
| `tether-event="shiftKey target.dataset.id"` | an element with a binding | more event data in `EventArgs::$data`: dotted paths, at most 32 | [Events](events-and-javascript.md#events) |
| `$this->bind('prop')` | a field | writes `value` or `checked` and the `tether-input`/`tether-change` that sets a `#[Bind]` property | [Components](components.md#forms-bind) |
| `tether-hook="Name"` | any element | the JavaScript object registered with `Tether.hook('Name', ...)` lives as long as the element | [Hooks](events-and-javascript.md#hooks-the-browser-side-of-a-component) |
| `tether-ref="name"` | any element | a name the server finds the element by: `$this->browser()->ref('name')` | [JavaScript interop](javascript-interop.md) |
| `tether-ignore` | any element | a render never touches the element, its attributes or its content | [tether-ignore](events-and-javascript.md#tether-ignore-elements-the-browser-owns) |
| `tether-keep="class style"` | any element | attributes a render never overwrites once the element is on the page | [Components](components.md#render) |
| `tether-reload` | an `<a>` | below an [App](apps.md), follow the link with a full page load | [Apps](apps.md) |
| `tether-id` | the root of a component | set by Tether, not by you: the component's id in the tab | [Components](components.md#render) |

Set by Tether on `<html>`, for your styles: `tether-live` while connected, `tether-offline`
while a lost connection is being restored, `tether-crashed` after the server crashed the tab.
The document gets the DOM events `tetherconnection` and, on the element, `tetherrefused`
([Events](events-and-javascript.md#how-events-leave-the-browser)).

A `tether-click` button inside a `<form>` needs `type="button"`, or it also submits the form.
A click on a link with `tether-click` does not follow the link.

## Modifiers

A modifier is a `.name` after the attribute: `tether-on-pointermove.throttle-50.passive`.

| Modifier | Meaning |
|---|---|
| `prevent` / `passive` | always / never call `preventDefault()`. Without either: a click on a link, a submit, a drop and a key matched by `key-`/`code-` are prevented, but only when the handler is not empty |
| `stop` | this binding handles the event and nothing further out does. `tether-click.stop=""`, with no handler, sends nothing and prevents nothing: a link or checkbox inside a clickable row |
| `self` | only when the event's target is this element |
| `once` | the first time only |
| `capture` | also fires when an inner binding handles the event, outermost first |
| `window` / `document` | listen there instead of on the element |
| `outside` | the event came from outside the element: close a menu on a click elsewhere |
| `nofield` | not when the target is an input, a select, a textarea or editable |
| `held` | only while a mouse button is down |
| `norepeat` | key events: not the auto-repeat of a held key |
| `key-<k>` / `code-<c>` | key events: `key` (`key-enter`, `key-k`, `key-escape`) or physical `code` (`code-keyw`); several allowed. `key-space`, `plus`, `minus`, `dot`, `comma`, `slash`, `equals` name the punctuation |
| `shift` `ctrl` `alt` `meta` `mod` | the modifier keys held (`mod` is Cmd on macOS, Ctrl elsewhere). With `key-`/`code-` filters exactly these, so `key-enter` is Enter alone and `ctrl.key-k` not ctrl+shift+k; without a filter they are required, others ignored |
| `mouse` `pen` `touch` | the pointer type |
| `left` `middle` `right` | the button |
| `send` `latest` `serial` `drop` `throttle-N` `debounce-N` | how events leave the browser: [Events](events-and-javascript.md#how-events-leave-the-browser). One at most |
| `delay-N` | for events that have an end (`mouseover`/`mouseout`, `focus`/`blur`, `touchstart`/`touchend`, `pointerdown`/`pointerup`): sent only if N ms pass before the end: hover intent, long press |
| `threshold-P` `margin-N` | `intersect`: percent visible, and pixels of margin |

Where each event is listened for: `resize`, `online`, `offline` and the other window events on
the window, `visibilitychange` and `selectionchange` on the document, so those need no `.window`
or `.document`. Every other event is listened for on the element, and the innermost matching
binding handles it.

## Not supported

- No DOM event object on the server: a handler gets the typed [`EventArgs`](events-and-javascript.md#events) for the event's type, plus what `tether-event` adds. Anything else is read from the browser with `$this->browser()`.
- No file content: a file input's chosen files arrive as `{name, size, type}`, and `tether-submit` leaves files out.
- One handler per event: the innermost binding handles it, unless an outer one says `capture`.
