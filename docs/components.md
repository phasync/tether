# Components

A component is a class extending `Tether\Component`. Its state is its properties; `render()`
turns them into HTML. It lives on the server for as long as it is on the page.

```php
namespace App;

use Tether\Component;

final class TodoList extends Component
{
    /** @var array<int, string> */
    public array $items = [];

    public string $draft = '';

    private int $next = 1;

    public function type(string $text): void
    {
        $this->draft = $text;
    }

    public function add(): void
    {
        if ('' !== trim($this->draft)) {
            $this->items[$this->next++] = trim($this->draft);
            $this->draft                = '';
        }
    }

    public function render(): string
    {
        $items = '';
        foreach ($this->items as $id => $text) {
            $items .= $this->child(TodoItem::class, ['text' => $text, 'onRemove' => fn () => $this->remove($id)], key: (string) $id);
        }
        $draft = htmlspecialchars($this->draft);

        return <<<HTML
            <section>
              <ul>{$items}</ul>
              <input value="{$draft}" tether-input="type" tether-keydown.key-enter="add">
            </section>
            HTML;
    }

    private function remove(int $id): void
    {
        unset($this->items[$id]);
    }
}
```

## render()

- Returns **exactly one root element**. Tether adds a `tether-id` attribute to it: that is how
  the browser knows which part of the page is which component.
- Returns HTML: **escape** everything that comes from users, with `htmlspecialchars()`.
- Is called whenever the component must be shown again; it should only read state. Loading
  data belongs in `mount()`, event handlers or `run()`.
- `$this->child(Class::class, $props, key: ...)` places a child component and returns its HTML.
- `$this->request()` is the PSR-7 request of the tab (see [State](state.md#the-tab-is-a-request)).

## Props and children

A child's props are its public properties, set by the parent on every render:
`$this->child(TodoItem::class, ['text' => $text])` sets `$item->text`. A prop that is not a
public property of the child is an error. `$tetherId` is taken: it is the component's id in the
tab (its root element's `tether-id`).

A child is identified by its class and **key**, or, without a key, by its class and position
among its siblings of that class. It keeps its state (its own properties, its coroutines) for
as long as the parent keeps placing it; when a render no longer places it, it is unmounted,
with its own children. Give children rendered in a loop a key, so each keeps its state when the
list changes.

A child is rendered again when it is new, when its props changed, or when it asked to be
(`requestRender()`). Otherwise the parent's frame reuses its last HTML and the browser leaves
its part of the page alone: focus, selection, a half-typed input survive a parent's update.

**Telling the parent something**: pass a closure. When the child calls it, the parent renders
afterwards, as Blazor's `EventCallback` does:

```php
// in the parent's render()
$this->child(TodoItem::class, ['text' => $text, 'onRemove' => fn () => $this->remove($id)], key: (string) $id);

// in TodoItem
public ?\Closure $onRemove = null;

public function remove(): void   // an event handler
{
    ($this->onRemove)();
}
```

The page's root component gets its props from what the route returns: `$t->mount($class, $props)`
in `Tether::from()`, or the `Page` of an [App](apps.md). They are PHP values of any kind, and
never leave the server: the live tab runs the route again. (The older `Tether::page()` takes
JSON props, which go to the browser and come back signed.)

`Tether::from()` writes the page's document itself. To use your own layout, pass `shell:`, a
closure `fn (string $root, string $scripts, Page $page): string` returning the whole HTML. Put
`$scripts` in the `<head>`: it is one module script (Tether's client), and modules wait for the
document, so your own `defer` scripts, which register hooks, run after it.

## Lifecycle

1. **First render.** The page is requested over HTTP: the root and its children are created,
   `mount()` runs for each, and the HTML goes out as a normal page. Nothing is live yet.
2. **Live.** The browser opens a WebSocket and says what it shows (its URL, or the root's
   props). Tether creates the components **again**, `mount()` runs again, and the fresh HTML
   replaces the first. From now on, each component's `run()` runs, and events reach handlers.
3. **Unmount.** A component leaves when its parent stops placing it, when navigation replaces
   the root, or when the tab closes (or reloads, or loses its connection). Its coroutines,
   `run()` and those started with `go()`, are cancelled.

Because the first render and the live tab are two instances, state in properties does not carry
over from 1 to 2, and anything `mount()` does, it does twice. That is harmless for reading
(loading a channel's messages) and wrong for side effects (posting a "joined" message): put
live-only work in `run()`.

After a lost connection, the browser reconnects and mounts from scratch: property state is gone.
State that must survive a reconnect, a reload or a deploy belongs in storage or the session.

## mount()

Runs once per instance, with the props set, before the first render: load what the component
shows.

```php
public function mount(): void
{
    $this->messages = Message::recent($this->room, 50);
}
```

It runs inside a render, so keep it short. It may not call `browser()`: nothing is in the browser yet.

While `mount()` waits (a query, a request), the component and its parents are not on screen, and
nothing else of the page is sent until it returns. For slow data, render a placeholder and load
in `run()`:

```php
public bool $loading = true;

public function run(): void
{
    $this->messages = Message::recent($this->room, 50);
    $this->loading = false;
    $this->requestRender();
}
```

## run()

Runs in a coroutine of its own while the component is live, and is cancelled when it leaves.

```php
final class Clock extends Component
{
    public string $time = '';

    public function run(): void
    {
        while (true) {
            $this->time = date('H:i:s');
            $this->requestRender();
            phasync::sleep(1);
        }
    }

    public function render(): string
    {
        return "<p>{$this->time}</p>";
    }
}
```

Wait with phasync's functions: `phasync::sleep()`, `phasync::readable($stream)` and
`phasync::writable($stream)` before reading or writing a network stream yourself,
`CurlMulti::await()` for curl. They let the worker's other tabs run meanwhile, with or without
phasync-ext, and an application that uses them works the same both ways.

phasync-ext makes the rest cooperative too: plain `sleep()`, blocking stream reads, MySQL queries
through mysqlnd. Without it, those hold up every tab in the worker while they wait: fine for
a quick query, not for a slow one. Write the application to work without the extension, and
let the extension make it faster.

Cancellation arrives as a `phasync\CancelledException` at the next wait. Let it pass: catching it
and carrying on would keep a component running that is no longer on the page. Use `finally`
for cleanup; code there may still write to the database, publish, and wait:

```php
public function run(): void
{
    Presence::join($this->room, $this->user);
    try {
        // ...
    } finally {
        Presence::leave($this->room, $this->user);   // the tab closed, or the component left
    }
}
```

`run()` may return: the component stays, it just has nothing more to do in the background.

A failure in `run()` (an exception it doesn't catch) is the component's: see [Errors](errors.md).

## go(): more coroutines

`$this->go(fn () => ...)` starts another coroutine of the component's, from an event handler,
`run()` or another of its coroutines. It is cancelled when the component leaves, and a failure
in it is the component's, as for `run()`. Following several topics at once takes one each:

```php
public function run(): void
{
    $this->go(function () {
        foreach (Swerve::subscribe("room:{$this->room}") as $json) {
            $this->messages[] = json_decode($json, true);
            $this->requestRender();
        }
    });
    $this->go(function () {
        foreach (Swerve::subscribe("typing:{$this->room}") as $name) {
            $this->typing[$name] = microtime(true);
            $this->requestRender();
        }
    });
}
```

A coroutine started with `phasync::go()` is not the component's: it belongs to the tab's
request, runs until it ends or the tab closes, and its failures are only logged. Use `go()`.

## requestRender()

Event handlers render their component by themselves when they return. Anywhere else (in
`run()`, in a coroutine, in a callback) call `$this->requestRender()` after changing state.

It does not render at once: it marks the component for the tab's next frame. Each tab has one
writer coroutine that wakes when something is marked, renders every marked component once,
parents first, and sends them in one message; then it waits at least 1/30 s before the next.
A component that changes 1,000 times a second is sent 30 times a second, with its latest
state. A client that reads slowly makes the writer wait, and changes keep collecting: it gets
fewer frames, never a backlog.

## Event handlers

Public methods of the component's own class are its event handlers, called from the browser:
see [Events and JavaScript](events-and-javascript.md). Everything public is callable by anyone
who has the page, with any arguments of the right types: see [Security](security.md). Keep
helpers `private`.
