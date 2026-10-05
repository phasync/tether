# Tether

Live server-side components for PHP. Each browser tab is tethered to its components on the
server over one WebSocket: clicks and keystrokes run PHP methods, and the page updates with
what changed. In the spirit of Blazor Server and Phoenix LiveView, for any PSR-15 application
served by the [swerve](https://github.com/phasync/swerve) application server.

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

To put it on a page, a route returns `Tether::from()`. In plain PHP, the whole `swerve.php`:

```php
use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tether\Tether;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return '/' === $request->getUri()->getPath()
            ? Tether::from($request, fn (Tether $t) => $t->mount(Counter::class, [], 'Counter'))
            : new Response(404, [], 'Not found');
    }
};
```

`vendor/bin/swerve swerve.php` serves it. One route answers the page and its live connection;
the closure runs for both, so a redirect or a 404 in it covers both.

- **Components** hold their state in properties, render HTML, nest with keys, and live for as
  long as they are on the page.
- **Coroutines**: `run()` runs in the background while the component is on the page (a clock,
  a chat subscription, a stream of tokens from an LLM), and so does anything it starts with
  `$this->go()`; all of it is cancelled when the component leaves.
- **Rendering** is batched per tab: many changes, one frame, at most 30 frames a second. A slow
  client gets fewer frames, never a backlog.
- **Apps**: for sites with several live pages, routes and navigation between them over the open
  connection: the layout, a call, a half-typed message survive moving between pages.
- **Many users**: swerve's publish/subscribe carries messages between tabs and workers.
- **JavaScript when you need it**: hooks give elements a JavaScript side (WebRTC, a canvas, an
  editor) that calls handlers, and that the server calls and waits for: `$this->browser()->call(...)`,
  `$ctx->fillRect(...)` on a canvas, `phasync::await()` on a Promise.
- **Failures** are contained by error boundaries; without one, the tab starts over.
- **Any framework**, or none: `Tether::from()` needs only the PSR-7 request. With a framework
  whose request state follows the request (not process-wide globals), such as mini, the tab is
  a request for as long as it is open, so its session and services work in components.

> Alpha: the API may still change. Measured on one core: about 18,000 frames a second, and
> about 150 KB of memory per open tab (Blazor Server: about 250 KB). On a 56-core server,
> 10,000 tabs updating continuously each got 22 to 29 frames a second.

MIT, with no dependencies beyond phasync and swerve: [the Ennerd philosophy](PHILOSOPHY.md).

## Where Tether fits

Tether is the top of the [phasync](https://github.com/phasync/phasync) stack, and needs the
layers under it: [swerve](https://github.com/phasync/swerve) keeps the tabs' connections open,
and PHP 8.3 or later runs it. You don't have to start there:

1. **Under PHP-FPM**, phasync already overlaps a request's slow calls (APIs, queries, files).
2. **With phasync-ext** (bundled in phasync; `--ext` or composer.json), the libraries you already use
   (MySQL through PDO, curl, Guzzle, file functions) wait cooperatively too, without changes.
3. **On swerve**, the same PSR-15 application stays loaded and serves thousands of connections
   per worker.
4. **With Tether**, pages of that application become live, next to its ordinary routes. A
   component's code waits the same way everything below it does: plain PHP, no promises.

## Documentation

1. [Running Tether](docs/running.md): installing, `Tether::from()`, mini, the middleware and
   Apps, swerve, the demo, production.
2. [Components](docs/components.md): props, render(), children, mount(), run(), go(), rendering.
3. [Apps and navigation](docs/apps.md): for several live pages: routes, pages, layouts that survive navigation.
4. [Events and JavaScript](docs/events-and-javascript.md): every DOM event with modifiers
   (`tether-on-pointermove.throttle-50`), typed event data, pacing and limits; hooks,
   tether-ignore.
5. [JavaScript interop](docs/javascript-interop.md): the server calls the browser and waits, V8Js-style
   (`call`, `executeString`, objects, Promises); the browser awaits handlers (`Tether.invoke`).
6. [State, sessions and many users](docs/state.md): the tab's request, sign-in, the database,
   publish/subscribe between tabs, presence, streaming from an LLM.
7. [Errors](docs/errors.md): error boundaries, crashes, logging.
8. [Security](docs/security.md): what the browser can do, escaping, origins, what the closure checks.

## Development

Swerve and mini (mini for the demo and the integration tests) are installed from the sibling
checkouts `../swerve` and `../mini-framework` (Composer path repositories, symlinked), so
changes to them take effect here at once.

- `vendor/bin/pest`: the PHP tests.
- `node tests/browser/demo.mjs http://127.0.0.1:8080/` and
  `node tests/browser/app.mjs http://127.0.0.1:8080` (and `interop.mjs`, `events.mjs`): the demo in headless Chrome, against a
  running demo (see [Running Tether](docs/running.md)).
- `node tests/load/tabs.mjs http://127.0.0.1:8080/fast?rate=50 1000 10`: 1,000 simulated tabs.
