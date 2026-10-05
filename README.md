# Tether

Live server-side components for PHP. Each browser tab is tethered to its components on the
server over one WebSocket: clicks and keystrokes run PHP methods, and the page updates with
what changed. In the spirit of Blazor Server and Phoenix LiveView, for any PSR-15 application
served by the [swerve](https://github.com/phasync/swerve) application server.

A route returns `Tether::from()`: one route answers the page and its live connection. In plain
PHP, the whole `swerve.php`:

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

and the component it mounts:

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

The button calls `increment()` on the server, and the paragraph updates in the browser. No
JavaScript to write, no API to design. The closure runs for the page and for every live
connection, so a redirect or a 404 in it covers both. From an empty directory to a running
page: [Running Tether](docs/running.md#quickstart).

- **Components** hold their state in properties, render HTML (by hand, or with Blade, Twig or
  any engine: [templates](docs/components.md#templates)), nest with keys, and live for as long
  as they are on the page.
- **Events**: every DOM event, with modifiers (`tether-on-pointermove.throttle-50`,
  `tether-keydown.key-enter`), typed event data, pacing and limits:
  [Events](docs/events-and-javascript.md), [attributes and modifiers](docs/attributes.md).
- **Coroutines**: `run()` runs in the background while the component is on the page (a clock,
  a chat subscription, a stream of tokens from an LLM), and so does anything it starts with
  `$this->go()`; all of it is cancelled when the component leaves.
- **JavaScript interop**: the server calls the browser and waits, V8Js-style
  (`$this->browser()->call(...)`, `$ctx->fillRect(...)` on a canvas, a Promise as a value);
  hooks give elements a JavaScript side that calls handlers; the browser awaits handlers with
  `Tether.invoke`: [JavaScript interop](docs/javascript-interop.md).
- **Rendering** is batched per tab: many changes, one frame, at most 30 frames a second. A slow
  client gets fewer frames, never a backlog.
- **Apps**: for sites with several live pages, routes and navigation between them over the open
  connection: the layout, a call, a half-typed message survive moving between pages
  ([Apps](docs/apps.md)). On a `from()` page, `tether-boost` on a link or its ancestor changes page
  without a reload (fetch and morph, a new connection: [Navigation](docs/running.md#navigation-without-a-reload)).
- **Many users**: swerve's publish/subscribe carries messages between tabs and workers
  ([State](docs/state.md)).
- **Failures** are contained by error boundaries; without one, the tab starts over
  ([Errors](docs/errors.md)).
- **Any framework**, or none: `Tether::from()` needs only the PSR-7 request. With a framework
  whose request state follows the request (not process-wide globals), such as mini, the tab is
  a request for as long as it is open, so its session and services work in components. The
  [playgrounds](docs/running.md#the-playgrounds) serve live pages from plain PHP, mini, Slim 4 and
  Laravel. Nothing is claimed for other frameworks.
- **Coming from Blazor Server?** [What maps to what](docs/blazor-comparison.md).

Alpha: the API may still change.

MIT. Tether depends on phasync (which ships the extension) and swerve, and bundles
[Idiomorph](https://github.com/bigskysoftware/idiomorph) 0.8.0 (BSD Zero Clause) for
morphing the page: [the Ennerd philosophy](PHILOSOPHY.md).

## Where Tether fits

Tether is the top of the [phasync](https://github.com/phasync/phasync) stack and needs the
layers under it: phasync, [swerve](https://github.com/phasync/swerve), which keeps the tabs'
connections open, and PHP 8.3 or later. What the playgrounds and their browser tests show:

- A live page next to ordinary routes of the same application: plain PHP, mini, Slim 4 and
  Laravel each serve one (`playground/`).
- The same behaviour with and without the extension: the browser tests run against each.
- Chat between tabs through swerve's publish/subscribe, a form, a canvas drawn from PHP, an
  event gallery, navigation across several live pages.
- A component's code waits with `phasync::sleep()`, `readable()` and `writable()`, the same way
  the rest of a phasync application does: plain PHP, no promises.

## Documentation

The [index](docs/README.md) lists every page:
[Running Tether](docs/running.md), [Components](docs/components.md),
[Apps](docs/apps.md), [Events and JavaScript](docs/events-and-javascript.md),
[Attributes and modifiers](docs/attributes.md), [JavaScript interop](docs/javascript-interop.md),
[State](docs/state.md), [Errors](docs/errors.md), [Security](docs/security.md),
[Testing](docs/testing.md), [Tether and Blazor Server](docs/blazor-comparison.md).

## Development

phasync and swerve come from Packagist. Mini, for the mini playground and the integration
tests, is a Composer path repository to the sibling checkout `../mini-framework`, a dev
dependency only.

- `vendor/bin/pest`: the PHP tests.
- `node tests/browser/<name>.mjs http://127.0.0.1:8080/`: the playgrounds in headless Chrome,
  against a running playground (`vendor/bin/swerve --http=8080 swerve.php` in its directory;
  see [Running Tether](docs/running.md#the-playgrounds)). Tests: `demo`, `app`, `interop`,
  `events`, `from`, `from-mini`, `slim`, `laravel`, `showcase`.
