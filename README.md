# Tether

Live server-side components for PHP. Each browser tab is tethered to its components on the
server over one WebSocket: clicks and keystrokes run PHP methods, and the page updates with
what changed. In the spirit of Blazor Server and Phoenix LiveView, built on the
[mini](https://github.com/frodeborli/fubber-mini) framework and the
[swerve](https://github.com/phasync/swerve) application server.

```php
final class Counter extends Tether\Component
{
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): string
    {
        return "<p>Clicked {$this->count} times <button tether-click=\"increment\">+1</button></p>";
    }
}
```

That is a live counter: the button calls `increment()` on the server, and the paragraph
updates in the browser. No JavaScript to write, no API to design.

- **Components** hold their state in properties, render HTML, nest with keys, and live for as
  long as they are on the page.
- **Coroutines**: `run()` runs in the background while the component is on the page (a clock,
  a chat subscription, a stream of tokens from an LLM) and is cancelled when it leaves.
- **Rendering** is batched per tab: many state changes, one frame, at most 30 frames a second.
  A slow client gets fewer frames, never a backlog.
- **Many users**: swerve's publish/subscribe carries messages between tabs and workers.
- **JavaScript when you need it**: hooks give elements a JavaScript side (WebRTC, a canvas, an
  editor) that calls handlers and is called by the server with `js()`.
- **Failures** are contained by error boundaries; without one, the tab starts over.
- **It is a mini application**: routes, sessions, the database and services work as in any mini
  request, in every component, for as long as the tab is open.

> Alpha: the API may still change. Measured on one core: about 18,000 frames a second, and
> about 150 KB of memory per open tab (Blazor Server: about 250 KB).

## Documentation

1. [Running Tether](docs/running.md): setting up an application, swerve, the demo, production.
2. [Components](docs/components.md): props, render(), children, mount(), run(), rendering.
3. [Events and JavaScript](docs/events-and-javascript.md): tether-click and friends, hooks,
   js(), tether-ignore.
4. [State, sessions and many users](docs/state.md): the tab's request, services, the database,
   publish/subscribe between tabs.
5. [Errors](docs/errors.md): error boundaries, crashes, logging.
6. [Security](docs/security.md): what the browser can do, escaping, origins, signed props.

## Development

Swerve and mini are installed from the sibling checkouts `../swerve` and `../mini-framework`
(Composer path repositories, symlinked), so changes to them take effect here at once.

- `vendor/bin/pest`: the PHP tests.
- `node tests/browser/demo.mjs http://127.0.0.1:8080/`: the demo in headless Chrome, against a
  running demo (see [Running Tether](docs/running.md)).
- `node tests/load/tabs.mjs http://127.0.0.1:8080/fast?rate=50 1000 10`: 1,000 simulated tabs.
