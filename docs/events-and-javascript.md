# Events and JavaScript

## Events

An attribute on an element names a handler: a public method of the component the element is in
(its nearest element with a `tether-id`).

| Attribute | Fires on | The handler gets |
|---|---|---|
| `tether-click="save"` | click | nothing |
| `tether-input="type"` | every change of an input's value | the value (`string`); for a checkbox, `true`/`false` |
| `tether-change="pick"` | change (select, checkbox, committed input) | the value; for a checkbox, `true`/`false`; for a `select multiple`, the selected values (`array`) |
| `tether-submit="send"` | form submit (prevented) | the form's fields: the last value per name, an `array` for a repeated name (and always for one ending in `[]`, which loses the `[]`); no files |
| `tether-keydown="add"` | a key | nothing; with `tether-key`, only that key combination (below) |

**Arguments from the element.** `tether-args` holds a JSON array, passed before the event's
own value: one handler serves a whole list.

```php
foreach ($this->messages as $m) {
    $html .= "<button tether-click=\"react\" tether-args='[{$m->id}, \"👍\"]'>👍</button>";
}

public function react(int $messageId, string $emoji): void { /* ... */ }
```

Build the JSON with `json_encode()` and escape it for the attribute
(`htmlspecialchars(json_encode($args))`) when it holds anything a user wrote.

**Key combinations.** `tether-key` names the combination: `Enter` is Enter with no modifier
key, `Shift+Enter`, `Ctrl+k`, `Alt+ArrowUp`, `Mod+Enter` (Cmd on macOS, Ctrl elsewhere).
Several are separated by spaces. A matching keydown calls the handler and does nothing else
(Enter in a textarea adds no line); anything else goes through as usual, so a chat input can
send on Enter and take Shift+Enter as a new line:

```html
<textarea tether-input="type" tether-keydown="send" tether-key="Enter"></textarea>
```

```php
public function send(array $form): void
{
    $text = trim($form['text'] ?? '');
    // ...
}

// <form tether-submit="send"><input name="text"><button>Send</button></form>
```

- A handler runs in a coroutine of its own: it may wait (`phasync::sleep()`, I/O, `js()`)
  without holding up anything else. Events for one component are started in the order they
  came, and don't wait for each other: a second click while the first handler still waits runs
  alongside it. Guard handlers that must not overlap (a Send button, a question to an LLM):

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
- Enter that commits an IME composition (Japanese, Chinese, Korean input) never fires `tether-keydown`.
- A click on a link with `tether-click` doesn't follow the link; `tether-key` keys don't type
  (Enter in a textarea sends, and adds no line).
- Arguments must match the handler's parameter types (`int`, `float`, `string`, `bool`, `array`,
  nullable, unions, variadics): a call that doesn't fit is refused and logged, and the handler
  doesn't run.

For an input you type into, keep the draft on the server with `tether-input` and act on it with
`tether-keydown`/`tether-key="Enter"` or a submit. The browser keeps focus and the cursor while
the component renders.

`tether-input` sends every keystroke. That is cheap (one small message, and at most 30 frames a
second back), but work a handler starts from it should be limited on the server: publish "is
typing" at most every few seconds, search when the user pauses (sleep in a coroutine started
with `go()`, and let the next keystroke cancel it). A form with `tether-submit` sends nothing
until it is submitted.

## Hooks: the browser side of a component

Some things only the browser can do: a camera, WebRTC, a canvas, a rich text editor, scrolling
to the bottom. A hook gives an element a JavaScript object for as long as the tab is live:

```html
<section tether-hook="Call">...</section>
```

```js
// html/app.js, loaded with Tether::page(..., head: '<script src="/app.js" defer></script>')
Tether.hook('Call', {
  mounted() {
    // this.el: the element; this.push(method, ...args): call a handler of its component
    this.push('ready', navigator.userAgent).then((reply) => console.log(reply));
  },
  updated() {
    // the element was rendered again
  },
  destroyed() {
    // the element left the page, or the tab lost its connection: clean up
  },
  // methods the server may call with js('Call.answer', ...)
  async answer(offer) {
    // ...
    return 'done';
  },
});
```

- `mounted()` runs when the tab goes live (not on the first render, which is not live), or when
  the element appears later. `destroyed()` when it leaves, and for every hook when the
  connection drops; they mount again after the reconnect, for the new components.
- `this.push(method, ...args)` calls a handler of the element's component with JSON arguments,
  and returns a promise of what the handler returned (JSON). It rejects when the handler fails
  or is not allowed, and when the connection closes.
- Register hooks before the tab goes live: in a script loaded with `defer` in the page's head.
- A hook's `mounted()` is also how JavaScript (and a browser test) knows the tab is live.
  While a lost connection is being restored, `<html>` has the attribute `tether-offline`; it is
  not set before the first connection.

## js(): calling the browser from the server

```php
public function measure(): void
{
    $elapsed = $this->js('Stopwatch.elapsed');        // a method of this component's hook
    $this->js('navigator.clipboard.writeText', 'hi');  // or a function, by its path from window
}
```

- `$this->js($function, ...$args)` sends the call and **waits for its result** (a returned
  promise is awaited), which comes back as PHP values. A JavaScript error comes back as
  `Tether\JsException`.
- `'Name.method'` calls a method of the hook named `Name` on this component's element or inside
  it (the first one); any other name is a path from `window`.
- The call reaches the browser in the frame after the component's current state: the page
  already shows what the handler changed when the function runs (render first, then scroll).
- From event handlers, `run()` and the component's other coroutines (`go()`); not from
  `render()` or `mount()`.
- Not interested in the result? Don't wait for it: `$this->go(fn () => $this->js('...'))`.

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
            $this->go(fn () => $this->js('Call.signal', $signal)); // don't wait
        }
    }

    // <button tether-click="start">Call</button>: the browser makes the offer
    public function start(): void
    {
        $this->js('Call.call');
    }

    // from the hook: this.push('signal', {type: 'offer', sdp: ...})
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
    this.pc.onicecandidate = (e) => e.candidate && this.push('signal', { type: 'candidate', candidate: e.candidate.toJSON() });
    this.pc.ontrack = (e) => { this.el.querySelector('#remote').srcObject = e.streams[0]; };
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    this.el.querySelector('#local').srcObject = stream;
    stream.getTracks().forEach((t) => this.pc.addTrack(t, stream));
  },
  destroyed() {
    this.pc?.close();
  },
  async call() {           // $this->js('Call.call') on the calling side
    await this.pc.setLocalDescription(await this.pc.createOffer());
    this.push('signal', { type: 'offer', sdp: this.pc.localDescription.sdp });
  },
  async signal(s) {        // from the other side, through the server
    if (s.type === 'offer') {
      await this.pc.setRemoteDescription(s);
      await this.pc.setLocalDescription(await this.pc.createAnswer());
      this.push('signal', { type: 'answer', sdp: this.pc.localDescription.sdp });
    } else if (s.type === 'answer') {
      await this.pc.setRemoteDescription(s);
    } else if (s.type === 'candidate') {
      await this.pc.addIceCandidate(s.candidate);
    }
  },
});
```

Cameras need a secure page: https, or http://localhost.
