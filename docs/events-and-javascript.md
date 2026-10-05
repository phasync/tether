# Events and JavaScript

## Events

`tether-on-<event>[.modifier]*="handler"` binds a DOM event to a public method of the component
the element is in (its nearest element with a `tether-id`). `tether-click`, `tether-input`,
`tether-change`, `tether-submit` and `tether-keydown` are short for the `tether-on-` form, and
take modifiers too: `tether-keydown.key-enter="add"`.

```html
<input tether-input="type" tether-keydown.key-enter="add">
<div tether-on-pointermove="trail" tether-on-wheel.prevent="zoom">...</div>
<section tether-on-intersect.threshold-50="visible" tether-on-resize="resized">...</section>
```

**Where the event comes from.** Every DOM event type works, also the ones that do not bubble
(focus, blur, mouseenter, mouseleave, pointerenter, pointerleave). The innermost matching
binding handles an event. `resize` listens on the window and `visibilitychange` on the
document, `online`/`offline` on the window: no suffix needed. `intersect` (the element enters or
leaves the viewport, or its scrolling ancestor) and `elementresize` (its size changes) are made
by observers.

| Modifier | Meaning |
|---|---|
| `prevent` / `passive` | always / never call `preventDefault()`. Without either: a click on a link, a submit, a drop and a key matched by `key-`/`code-` are prevented, but only when the handler is not empty |
| `stop` | this binding handles it and nothing further out does. `tether-click.stop=""`, with no handler, sends nothing and prevents nothing: a link or checkbox inside a clickable row |
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
| `latest` `send` `serial` `drop` `throttle-N` `debounce-N` | how events leave the tab (below) |
| `delay-N` | for events that have an end (`mouseover`/`mouseout`, `focus`/`blur`, `touchstart`/`touchend`, `pointerdown`/`pointerup`): sent only if N ms pass before the end: hover intent, long press |
| `threshold-P` `margin-N` | `intersect`: percent visible, and pixels of margin |

Invalid modifiers, unknown `tether-*` attributes and contradictions (`passive` with `prevent`)
are reported with `console.error`.

**What the handler gets.** The arguments from the element, then the value of a field, then
optionally a typed event as the **last parameter**:

```php
use Tether\Event\PointerEventArgs;

public function trail(PointerEventArgs $e): void { $this->x = $e->clientX; }
public function type(string $value): void { /* tether-input: the value */ }
public function add(KeyboardEventArgs $e): void { if ($e->shiftKey) { /* ... */ } }
public function pick(string $value, ChangeEventArgs $e): void { /* both */ }
```

A parameter typed as `Tether\Event\EventArgs` or a subclass receives the event's data and is not
counted among the arguments; every property has a default. Without one, the handler gets no
event data. `$e->type` is the DOM event type, `$e->data` the whole payload.

| Class | Events | Data |
|---|---|---|
| `MouseEventArgs` | click, dblclick, auxclick, contextmenu, mousedown/up/enter/leave/move/over/out | clientX/Y, pageX/Y, offsetX/Y, screenX/Y, button, buttons, detail, shift/ctrl/alt/metaKey |
| `PointerEventArgs` | pointer* | mouse data, pointerId, pointerType, width, height, pressure, tiltX/Y, isPrimary |
| `WheelEventArgs` | wheel | mouse data, deltaX/Y/Z, deltaMode |
| `TouchEventArgs` | touchstart/move/end/cancel | touches, targetTouches, changedTouches (identifier, clientX/Y, pageX/Y, force; at most 10), modifier keys |
| `KeyboardEventArgs` | keydown, keyup, keypress | key, code, location, repeat, isComposing, modifier keys |
| `FocusEventArgs` | focus, blur, focusin, focusout | relatedId (the other element's `id`), name |
| `ChangeEventArgs` | input, change | value, name, inputType; in a form: fields |
| `SubmitEventArgs` | submit | fields, submitter (its name) |
| `DragEventArgs` | drag, dragstart/end/enter/over/leave, drop | mouse data, types, effectAllowed, dropEffect, files (name, size, type), text |
| `ClipboardEventArgs` | copy, cut, paste | text (a paste: at most 64 KiB), types |
| `ScrollEventArgs` | scroll | scrollTop, scrollLeft, scrollHeight, clientHeight, clientWidth; the page: scrollX, scrollY |
| `SizeEventArgs` | resize, elementresize | innerWidth, innerHeight, devicePixelRatio; or width, height of the element |
| `IntersectEventArgs` | intersect | isIntersecting, ratio |
| `EventArgs` | everything else, custom events | `detail` of a CustomEvent, when JSON can carry it |

A mismatch (a string where the class wants an int) refuses the call. A drop target needs
`tether-on-dragover.prevent=""` to allow a drop; `drop` is prevented by itself.

**`tether-event`** adds data to what is sent: dotted paths of primitives, up to 32. `target` is the
innermost node, `currentTarget` the element with the binding.

```html
<button tether-click="pick" tether-event="shiftKey target.dataset.id">...</button>
```

```php
public function pick(EventArgs $e): void { $id = $e->data['target.dataset.id'] ?? null; }
```

**Arguments from the element.** `tether-args` holds a JSON array, passed before the event's
own value: one handler serves a whole list. `tether-args-click` and the like win for that event.

```php
foreach ($this->messages as $m) {
    $html .= "<button tether-click=\"react\" tether-args='[{$m->id}, \"👍\"]'>👍</button>";
}

public function react(int $messageId, string $emoji): void { /* ... */ }
```

Build the JSON with `json_encode()` and escape it for the attribute
(`htmlspecialchars(json_encode($args))`) when it holds anything a user wrote.

**Fields.** `tether-input` and `tether-change` on a field send its value: a string, a number for
`number` and `range`, `true`/`false` for a checkbox, the value of the chosen one for a radio,
an `array` for `select multiple`. On a `<form>` (the events bubble from its fields) they send
`[fields, name]`: all the fields, and the name of the one that changed. `tether-submit` sends the
form's fields: the last value per name, an `array` for a repeated name or one ending in `[]`
(which loses the `[]`); no files.

```php
public function changed(array $fields, string $name): void { /* <form tether-input="changed"> */ }
public function send(array $form): void { /* <form tether-submit="send"><input name="text"> */ }
```

**Keys.** `tether-keydown.key-enter` is Enter alone and does nothing else (Enter in a textarea
adds no line); other keys go through, so a chat input sends on Enter and takes Shift+Enter as a
new line. `tether-on-keydown.document.key-slash.nofield="search"` is a shortcut that does not
fire while typing. Enter that commits an IME composition (Japanese, Chinese, Korean) never
fires, and input events wait for the composition to end: the text is sent once, when it is
final.

## How events leave the browser

| Policy | What it does | Default for |
|---|---|---|
| `send` | every event, at once | clicks, keys, pointer down/up, enter/leave, focus, touch start/end, change, drop, visibility |
| `latest` | one in flight; what happens meanwhile is replaced by the newest; sent when the handler has finished | `input` |
| `throttle-N` | at most one every N ms: the first at once, and the newest after that | pointermove, mousemove, touchmove, drag, scroll, resize (all 50 or 100 ms); wheel: 100, with the deltas summed |
| `debounce-N` | after N ms without a new one | |
| `serial` | every event, one at a time, in order (at most 64 waiting) | |
| `drop` | not while one is running | |

A handler's reply is what frees `latest`, `serial` and `drop`: a slow handler is never sent more
events than it can finish. A paced event that waits goes out before an event of another binding,
so `debounce-300` typing followed by a click arrives in that order. Replies are handled before
the patches that come with them, so a typed-over field is not overwritten by an answer to
something older: while an event of the focused field is in flight or waiting, its value is left
alone. A closed connection drops everything waiting.

**Limits.** A tab may send 200 events a second, with bursts of 400 (the browser keeps to 90 % of
it, so an honest page never hits it); a tab that exceeds it is closed with code 4429. At most 64
handlers run at once per tab: more are refused (the reply, or a `console.error` for events
without one, says so), and a message over 512 KiB is not sent. `new Tether\Limits(eventsPerSecond: 500, burst: 1000, running: 128, bytes: 1048576)`
changes them: the last parameter of `Tether::from()`, and of the `Tether` middleware and `App`
constructors. Tether.debug (`?tether-debug`) tells in the console why an event was dropped or
held back.

## Handlers

- A handler runs in a coroutine of its own: it may wait (`phasync::sleep()`, I/O, a call to the [browser](javascript-interop.md))
  without holding up anything else. Events for one component are started in the order they
  came, and don't wait for each other: a second click while the first handler still waits runs
  alongside it (or use `serial`/`drop`). Guard handlers that must not overlap (a Send button, a
  question to an LLM):

  ```php
  public function ask(array $form): void
  {
      if ($this->busy) {
          return;
      }
      $this->busy = true;
      try {
          // ...
      } finally {
          $this->busy = false;
      }
  }
  ```
- When it returns, its component renders.
- `$this->navigate($url, replace: true)` replaces the history entry instead of adding one.
- Arguments must match the handler's parameter types (`int`, `float`, `string`, `bool`, `array`,
  nullable, unions, variadics): a call that doesn't fit is refused and logged, and the handler
  doesn't run.
- A click on a link with `tether-click` doesn't follow the link.

For an input you type into, keep the draft on the server with `tether-input` and act on it with
`tether-keydown.key-enter` or a submit. The browser keeps focus and the cursor while the
component renders. Work a handler starts from every keystroke should be limited on the server:
publish "is typing" at most every few seconds, or search when the user pauses (`debounce-300`).
A form with `tether-submit` sends nothing until it is submitted.

## Hooks: the browser side of a component

Some things only the browser can do: a camera, WebRTC, a canvas, a rich text editor, scrolling
to the bottom. A hook gives an element a JavaScript object for as long as the tab is live:

```html
<section tether-hook="Call">...</section>
```

```js
// html/app.js, loaded with $t->mount(..., head: '<script src="/app.js" defer></script>')
Tether.hook('Call', {
  mounted() {
    // this.el: the element; this.invoke(method, ...args): call a handler of its component
    this.invoke('ready', navigator.userAgent).then((reply) => console.log(reply));
  },
  updated() {
    // the element was rendered again
  },
  destroyed() {
    // the element left the page, or the tab lost its connection: clean up
  },
  // methods the server may call: $this->browser()->hook('Call')->answer(...)
  async answer(offer) {
    // ...
    return 'done';
  },
});
```

- `mounted()` runs when the tab goes live (not on the first render, which is not live), or when
  the element appears later. `destroyed()` when it leaves, and for every hook when the
  connection drops; they mount again after the reconnect, for the new components.
- `this.invoke(method, ...args)` calls a handler of the element's component with JSON arguments,
  and returns a promise of what the handler returned (JSON). The handler must be marked
  `#[Tether\Invokable]`. It rejects when the handler fails or is not allowed, after a timeout,
  and when the connection closes ([JavaScript interop](javascript-interop.md)).
- The server calls the hook's methods, and reads its properties, with `$this->browser()->hook('Call')`;
  it waits for an `async mounted()`.
- Register hooks before the tab goes live: in a script loaded with `defer` in the page's head.
- A hook's `mounted()` is also how JavaScript (and a browser test) knows the tab is live.
  While a lost connection is being restored, `<html>` has the attribute `tether-offline`; it is
  not set before the first connection.

## Calling the browser from the server

`$this->browser()->call('Call.answer', ...)`, `executeString()`, element references and the rest
are in [JavaScript interop](javascript-interop.md). To not wait for a result, start the call in a
coroutine: `$this->go(fn () => $this->browser()->call('...'))`.

## tether-ignore: elements the browser owns

A `<video>` playing a stream, a map, an editor: once it is on the page, renders must not touch
it. `tether-ignore` keeps the element, its attributes and everything inside it as the browser
has them:

```html
<video id="remote" tether-ignore autoplay playsinline></video>
```

Give such elements an `id`, so a re-render that moves things around still finds them.

## Example: WebRTC signalling

The server passes offers, answers and candidates between two tabs over
[publish/subscribe](state.md#many-users-publish-and-subscribe); the browsers do the rest.

```php
final class Call extends Component
{
    public string $peer = '';      // who to call: a prop, from the page

    private string $me = '';

    public function mount(): void
    {
        $this->me = $_SESSION['user'];  // who I am: from the session, never from the browser
    }

    public function run(): void
    {
        foreach (Swerve\Swerve::subscribe("call:{$this->me}") as $message) {
            $signal = json_decode($message, true);
            $this->go(fn () => $this->browser()->hook('Call')->signal($signal)); // don't wait
        }
    }

    // <button tether-click="start">Call</button>: the browser makes the offer
    public function start(): void
    {
        $this->browser()->hook('Call')->call();
    }

    // from the hook: this.invoke('signal', {type: 'offer', sdp: ...})
    #[Tether\Invokable]
    public function signal(array $signal): void
    {
        Swerve\Swerve::publish("call:{$this->peer}", json_encode($signal));
    }

    public function render(): string
    {
        return '<div tether-hook="Call"><video id="local" tether-ignore autoplay muted playsinline></video><video id="remote" tether-ignore autoplay playsinline></video></div>';
    }
}
```

```js
Tether.hook('Call', {
  async mounted() {
    this.pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    this.pc.onicecandidate = (e) => e.candidate && this.invoke('signal', { type: 'candidate', candidate: e.candidate.toJSON() });
    this.pc.ontrack = (e) => { this.el.querySelector('#remote').srcObject = e.streams[0]; };
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    this.el.querySelector('#local').srcObject = stream;
    stream.getTracks().forEach((t) => this.pc.addTrack(t, stream));
  },
  destroyed() {
    this.pc?.close();
  },
  async call() {           // $this->browser()->hook('Call')->call() on the calling side
    await this.pc.setLocalDescription(await this.pc.createOffer());
    this.invoke('signal', { type: 'offer', sdp: this.pc.localDescription.sdp });
  },
  async signal(s) {        // from the other side, through the server
    if (s.type === 'offer') {
      await this.pc.setRemoteDescription(s);
      await this.pc.setLocalDescription(await this.pc.createAnswer());
      this.invoke('signal', { type: 'answer', sdp: this.pc.localDescription.sdp });
    } else if (s.type === 'answer') {
      await this.pc.setRemoteDescription(s);
    } else if (s.type === 'candidate') {
      await this.pc.addIceCandidate(s.candidate);
    }
  },
});
```

Cameras need a secure page: https, or http://localhost.
