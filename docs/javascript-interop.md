# JavaScript interop

The server calls the browser, and the browser calls the server, and in both directions the
caller waits for the answer: plain PHP, plain JavaScript `await`. Only the coroutine that
calls waits; the tab's other handlers and `run()` go on.

## The server calls the browser

`$this->browser()` is the browser of a component. It works in event handlers, `run()` and
coroutines started with `go()`, not in `render()` or `mount()`.

```php
public function paint(): void
{
    $ctx = $this->browser()->executeString('return document.getElementById("canvas").getContext("2d");');
    $ctx->fillStyle = 'rgb(200, 0, 0)';
    $ctx->fillRect(10, 10, 50, 50);

    try {
        $width = $this->browser()->call('Charts.measure', $this->series);   // a function of window
        $this->browser()->call('Charts.draw', $ctx, $width * 2);            // $ctx goes back as the object
    } catch (Tether\JsException $e) {
        $this->error = $e->jsName . ': ' . $e->getMessage();               // e.g. "TypeError: ..."
    }
}
```

| Call | Does |
|---|---|
| `call('a.b.c', ...$args)` | `window.a.b.c(...$args)`, with `a.b` as `this` |
| `executeString($code, ['x' => 1])` | runs the body of a function, `x` a parameter; returns what it returns |
| `new('Audio', $url)` | `new Audio($url)` |
| `import('/chart.js')` | `import()` of a module, once; its namespace as an object |
| `hook('Name')` | the live instance of the component's `tether-hook="Name"` (after an async `mounted()`) |
| `ref('canvas')` | the element marked `tether-ref="canvas"` in the component, or null |
| `find('input')`, `find()` | the first element matching a selector in the component, or the component's own |
| `window`, `document` | objects for the browser's own |
| `within(120.0, fn () => ...)` | what is called inside waits that many seconds, not the default |

**Results.** What JSON carries (null, booleans, numbers, strings, plain objects and arrays,
the last two as PHP arrays) comes by value. Everything else (a DOM element, a function, a
Canvas context, a Promise) comes as a `Tether\JsObject`, which stands for it in the browser:

```php
$input = $this->browser()->ref('name');
$input->value = 'Ada';          // input.value = 'Ada'
$input->focus();               // input.focus()
isset($input->checked);        // input.checked != null
$input['dataset'];             // input['dataset']; also unset(), count() and (string)
$input->value();               // a copy of the object as JSON data
```

An object may be given back as an argument. The browser keeps it as long as the PHP object
lives; when the PHP object is destroyed or the component leaves the page, it is released. One
that outlived its component, or an element a render removed, throws `JsStaleException`: ask
again with `ref()` where you use it, and don't keep elements across renders.

**Promises** are not awaited by themselves, so two can run at once; wait for them with
`phasync::await()`:

```php
use phasync;

$a = $this->browser()->call('fetch', '/a');
$b = $this->browser()->call('fetch', '/b');
[$ra, $rb] = [phasync::await($a, 5.0), phasync::await($b, 5.0)];   // JsObjects of the Responses
$text = phasync::await($ra->text());                                // the body, a string
```

**Failures.** All are `Tether\JsException` (`$jsName`, `getMessage()`, `$jsStack` of the
JavaScript error): a thrown error, a rejected Promise, a result that can't be sent (a
circular structure, a BigInt, an answer over the message limit). Subclasses: `JsTimeoutException`
(no answer in `Limits::$callTimeout` seconds, 10 by default), `JsStaleException`,
`JsLimitException` (too many calls or objects at once). A tab that disconnects cancels every
call that waits, like any coroutine of the component: nothing catches that. A late answer to a
call that timed out is dropped and its objects released.

**Cost.** Every property read, write and call is a round trip to the browser. For a loop, write
a function in a [hook](events-and-javascript.md#hooks-the-browser-side-of-a-component) or a module
and call it once.

**Order.** A call goes out after the HTML of its component is in the browser, but it does not
send a render that is waiting. Call `$this->awaitRender()` first when the call needs the
changes the handler has made (render, then scroll):

```php
$this->messages[] = $text;
$this->awaitRender();
$this->browser()->call('Chat.scrollToEnd');
```

`executeString()` needs a Content-Security-Policy that allows `'unsafe-eval'`; every other call
works under a strict one.

### Built-in helpers are calls

```php
$b = $this->browser();
$b->ref('name')->focus();                                         // focus, select
$b->ref('name')->select();
$b->ref('log')->scrollTo(0, 9999);                                // scrollTo
$b->call('navigator.clipboard.writeText', $text);                 // clipboard (a Promise)
$b->call('localStorage.setItem', 'theme', 'dark');                // localStorage, sessionStorage
$theme = $b->call('localStorage.getItem', 'theme');
$b->document->title = 'Inbox (3)';                                // title
$b->call('history.pushState', null, '', '/inbox');                // history
$width = $b->window->innerWidth;
```

## The browser calls the server

A hook, or any script, calls a handler of the component its element is in and waits for the
value it returns:

```php
final class Search extends Component
{
    #[Tether\Invokable]
    public function suggest(string $prefix): array
    {
        return $this->index->startingWith($prefix);   // JSON-encodable
    }
}
```

```js
Tether.hook('Search', {
  async mounted() {
    try {
      const names = await this.invoke('suggest', 'Ad');       // in a hook: this.invoke
      // or from anywhere: await Tether.invoke(element, 'suggest', 'Ad')
    } catch (error) {
      console.error(error.name, error.message);               // TimeoutError, or Error
    }
  },
});
```

- Only a handler marked `#[Tether\Invokable]` gives its return value to the browser; any
  other handler is refused when called this way. (Handlers called by `tether-click` and the
  like return nothing to anyone.)
- The promise rejects when the handler throws (always with the message "The handler failed":
  the server's text is logged, not sent), when the call is refused, after `Tether.timeout`
  milliseconds (10 000; `Tether.invoke.within(ms, element, 'method', ...args)` for one call),
  and when the connection closes. A handler that is slow to answer finishes anyway; its late
  answer is dropped.
- Arguments must match the handler's parameter types, as for events.
- A failing handler is a failure of the component like any other: an error boundary above it
  catches it, or the tab starts over (see [Errors](errors.md)).

## Rules

- **Names and code are the server's.** Never build a function path, a method name, an
  `executeString()` code string or a module URL from anything the browser sent. Values go in
  as arguments (`executeString()` takes them as named parameters, never as code; a name that is
  not an identifier is refused).
- The browser holds only the objects it handed out, and an answer to a call nobody made counts
  against the tab's event budget like any abuse.
  Member names `__proto__`, `constructor` and `prototype`, and the paths `eval` and `Function`,
  are refused in the browser.
- The server runs only handlers marked `#[Tether\Invokable]` for the browser's calls, and
  what they return is data: check their arguments like any input.
- `Limits` bounds it: `calls` (32 outstanding calls per tab), `handles` (4096 browser objects
  held), `callTimeout` (10 seconds).

## Example: a hook the server and the browser share

`Stopwatch` runs by itself in the browser, tells the server when it starts, and the server asks
it for the time:

```php
final class Timer extends Component
{
    public ?int $ms = null;

    #[Tether\Invokable]
    public function hello(string $agent): string
    {
        return 'hello ' . $agent;
    }

    public function measure(): void
    {
        $this->ms = $this->browser()->hook('Stopwatch')->elapsed();
    }

    public function render(): string
    {
        return '<section tether-hook="Stopwatch"><button tether-click="measure">' . ($this->ms ?? 'Ask') . '</button></section>';
    }
}
```

```js
Tether.hook('Stopwatch', {
  mounted() {
    this.start = performance.now();
    this.invoke('hello', navigator.userAgent).then(console.log);
  },
  elapsed() {                      // $this->browser()->hook('Stopwatch')->elapsed()
    return Math.round(performance.now() - this.start);
  },
});
```
