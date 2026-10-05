# Running Tether

Tether runs on the [swerve](https://github.com/phasync/swerve) application server, in any
PSR-15 application. This page starts a live page in a fresh project with no framework, as
`playground/plain` does, then sets one up with the [mini](https://github.com/frodeborli/fubber-mini)
framework, as the demo in `playground/demo` does.

## Quickstart: a fresh project

Tether is not on Packagist yet: install it from its repository. `composer.json`:

```json
{
    "require": {
        "php": ">=8.3",
        "phasync/tether": "dev-main",
        "phasync/swerve": "^0.1.0-beta5"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/phasync/tether" }
    ],
    "autoload": { "psr-4": { "App\\": "src/" } },
    "minimum-stability": "beta"
}
```

`src/Counter.php`, a component:

```php
<?php

namespace App;

use Tether\Component;

final class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): string
    {
        return "<main><p>Clicked <b id=\"count\">{$this->count}</b> times <button id=\"inc\" tether-click=\"increment\">+1</button></p></main>";
    }
}
```

`swerve.php`, the application: a PSR-15 handler that answers `/` with a live page and
everything else with a 404.

```php
<?php

use App\Counter;
use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tether\Tether;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('/' === $request->getUri()->getPath()) {
            return Tether::from($request, fn (Tether $t) => $t->mount(Counter::class, [], 'Counter'));
        }

        return new Response(404, [], 'Not found');
    }
};
```

```
composer install
vendor/bin/swerve --http=8080 swerve.php
```

Open http://localhost:8080/ and click the button: `increment()` runs on the server and the
count changes in the browser. `Tether::from()` is the whole integration, see below.

Working on Tether or swerve at the same time? Use path repositories to the checkouts instead,
and require `"phasync/swerve": "@dev"` and `"phasync/tether": "@dev"` (a path repository only
has `dev-main`):

```json
"repositories": [
    { "type": "path", "url": "../phasync-tether", "options": { "symlink": true } },
    { "type": "path", "url": "../swerve", "options": { "symlink": true } }
]
```

phasync ships an extension that makes blocking PHP calls (reads and writes on PHP streams,
queries through mysqlnd) give way to other coroutines, and lifts the connection limit
of a worker; see [swerve's production guide](https://github.com/phasync/swerve/blob/main/docs/production.md#sizing).
Enable it with `"extra": {"phasync": {"ext": true}}` in your composer.json, or start swerve
with `--ext` (swerve stops if it cannot load). Tether behaves the same with and without it, as
long as components wait with phasync's functions (see [Components](components.md#run)).

## A live page: Tether::from()

A route returns `Tether::from($request, $closure)`. One route answers both the page (a GET) and
its live connection (the WebSocket upgrade the page's script opens, to the same URL, with the
same cookies): nothing else to mount, no client files to serve. In plain PHP, `swerve.php` is a
PSR-15 handler with a tiny router:

```php
<?php

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tether\Tether;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (preg_match('#^/chat/(\w+)$#', $request->getUri()->getPath(), $room)) {
            return Tether::from($request, fn (Tether $t) => $t->mount(Chat::class, ['room' => $room[1]], "#{$room[1]}"));
        }

        return new Response(404, [], 'Not found');
    }
};
```

The closure runs for the page and again for every live connection, reconnects included, inside
your handler: route parameters, the session and authentication apply, and it is where a route
says no. It returns

- `$t->mount(Class::class, $props, $title, $head)`: the page's root component (a `Page`). Props
  never leave the server, so they can be any PHP values, objects included.
- a response: for the page it goes out as it is; for a live connection, a redirect sends the
  browser there (signed out: `new Response(302, ['Location' => '/login'], '')`), and any other
  (404, 403) leaves the page as rendered, with no live connection and no retries.

A live tab starts from what the closure returns, so a reconnect mounts the components anew:
their state is gone (see [State](state.md#the-tab-is-a-request)). Work that must happen once, not
for the page and again for the tab, checks `$t->live`. Only GET and HEAD are answered.

Options, after the closure:

- `shell`: your template makes the whole document. `fn (string $root, string $scripts, Page
  $page): string` gets the root component's HTML, the script block and the page, and returns
  HTML. Put `$scripts` in the `<head>`: it is a deferred module, so your `defer` scripts that
  call `Tether.hook()` run after it. A shell that leaves it out is an error. Without a shell,
  Tether writes a document with the page's title and `head`.
- `nonce`: for a Content-Security-Policy that needs one on the inline script.
- `origins`: other origins whose pages may open the live connection (the Origin must be the
  host otherwise); `enter`: see below.
- `version`: your deploy id (a git sha). A page names the version that served it when its
  connection opens, and a server running another one closes it (code 4001) and the page reloads.
  Without it the version is a hash of Tether's client, so a page served before
  an upgrade of Tether reloads; the `App` constructor takes the same `version`. During a rolling
  deploy two versions answer one address, and a reload may land on the other one and reload again
  until the deploy is done.

## With mini

Add `"fubber/mini": "@dev"` to `require` (and the checkout of mini as a path repository, until it is
on Packagist). The layout of a mini application, plus `swerve.php`:

```
_routes/chat/_.php        a live page: /chat/{room}, through Tether::from()
_routes/login.php         an ordinary route: the sign-in form posts here
src/                      your components (App\...)
html/                     public files: index.php for PHP-FPM, your .js and .css
swerve.php                the application, for swerve
```

`swerve.php` returns mini's request pipeline:

```php
<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Mini;

$dispatcher = Mini::$mini->get(RequestDispatcher::class);
// Middleware and services are registered here, before \mini\bootstrap()

\mini\bootstrap();   // mini's services work from here on: create the schema, say
Schema::ensure();   // (every worker runs this file at the same time: see State)

return $dispatcher;
```

mini's services (`mini\db()`, the session) only work after `mini\bootstrap()`, which ends
registration: `addMiddleware()` and `addService()` go before it. Without it, mini gets ready by
itself when the first request comes, too late for `swerve.php`.

Write mini's functions with a leading backslash, `\mini\bootstrap()`, `\mini\db()`: in a file
with `use mini\Mini;`, PHP resolves `mini\bootstrap()` through that alias (to
`mini\Mini\bootstrap()`), and in a namespaced class to `YourNamespace\mini\db()`.

`_routes/chat/_.php` is the page for `/chat/{room}` (`$_GET[0]` is the segment):

```php
<?php

use mini\Dispatcher\RequestDispatcher;
use Tether\Tether;

return Tether::from(
    \mini\request(),
    fn (Tether $t) => $t->mount(App\Chat::class, ['room' => $_GET[0]], "#{$_GET[0]}"),
    enter: RequestDispatcher::within(...),
);
```

A live tab runs after its upgrade request was answered, outside mini's handling of it.
`enter` makes the tab the work of that request for mini: `mini\request()`, `$_COOKIE`,
`$_SESSION` and mini's Scoped services are the tab's, in every component, for as long as it is
open (see [State](state.md)). Without `enter`, `$this->request()` in a component still gives the
request; only the ambient state is missing.

## Navigation without a reload

By default a link on a `from()` page is a full page load. `tether-boost` on a link, or on any
element above links (`<body>`, `<nav>`), makes the links inside fetch the next page and morph it
into the current one: the scripts, styles and fonts stay loaded, there is no white flash, and the
URL and the title change. `tether-boost="off"` on a link or an element turns it off again below.

```html
<body tether-boost>
  <a href="/chat/dev">Dev</a>
  <a href="/files/export" tether-boost="off">Export</a>
```

The new page opens its own live connection and the old one is closed: **there is no continuity**,
the new page's components start from their props and nothing is carried over (an App is the way
to keep state across pages). Back and forward go the same way, and restore the scroll position.

- Only a plain left click on a same-origin `http(s)` link, with no `target` other than `_self`,
  no `download`, no `tether-click`, and not a `#fragment` of the page you are on. Everything else
  is the browser's.
- A response that is not a Tether page (an error, a redirect off the site, plain HTML, a page of
  an App) is loaded the usual way, as is a failed request.
- Only the title and the `<body>` are taken from the next page. Scripts and styles in `<head>` are
  not run again, and scripts in the body are not run: the layout's head must be the same on every
  boosted page.
- A newer click cancels a navigation still being fetched. While one is, `<html>` has
  `tether-navigating`, for a progress bar: `html[tether-navigating] body { cursor: progress }`.
- `tetherboost` is fired on `window` after the swap, with `detail.url`.

## Pages with navigation, and the middleware

Two older ways. The middleware is deprecated and goes at 1.0. An App stays: it is the only way to keep the connection, and the state, across pages.

- **An App** ([Apps and navigation](apps.md)): a class with routes to live pages, and
  navigation between them over the open connection, so the layout, a call or a half-typed
  message survive moving between pages. Mounted where the framework routes a path and
  everything below it; with mini, `_routes/__DEFAULT__.php` returns
  `new App\Chat(enter: mini\Dispatcher\RequestDispatcher::within(...))` (or
  `_routes/chat/__DEFAULT__.php` for an App at `/chat/`).
- **The middleware** (deprecated): `Tether::page(Root::class, $props, $title, $head)` as a route's
  response, with Tether's middleware serving the client's files and the live connection at
  `/_tether/`. The props travel through the browser, signed.

```php
$dispatcher = Mini::$mini->get(RequestDispatcher::class);
$dispatcher->addMiddleware(new Tether\Tether(enter: RequestDispatcher::within(...)));
\mini\bootstrap();   // after the middleware, if swerve.php uses mini's services below

return $dispatcher;
```

## With another framework

Anything that gives swerve a PSR-15 request handler works, provided the framework keeps request
state per request, not in globals shared by the process: swerve runs many requests, and all
open tabs, concurrently in each worker. `Tether::from()` needs only the PSR-7 request the
route was given. An App is a request handler, so Slim, for example, can route to it:

```php
// swerve.php
$app = Slim\Factory\AppFactory::create();
$app->any('/[{path:.*}]', new App\Chat());   // the App takes every path
return $app;
```

Components find the tab's request (its cookies, the session) the way your framework makes a
request current: `enter` is where Tether hands it over, as `RequestDispatcher::within()` does
for mini. Its signature is `fn (ServerRequestInterface $request, Closure $tab): void`: make
`$request` current, call `$tab()` (it returns when the tab closes), and clean up.

## Running

```
vendor/bin/swerve --http=8080 --public=html swerve.php
```

`--public=html` serves your static files. Open http://localhost:8080/. With `--watch`, swerve
restarts its workers when a PHP file changes; open tabs reconnect and mount again.

A tab's connection stays with the worker that accepted it, and each worker holds its tabs'
components in memory. Swerve starts one worker per CPU by default (`--workers`); they share nothing but what goes
through storage or [publish/subscribe](state.md#many-users-publish-and-subscribe).

Under PHP-FPM or `php -S`, pages render but don't go live: there is no WebSocket. Swerve is the
server for Tether.

## The playgrounds

Each is a Composer project: `composer install`, then `vendor/bin/swerve --http=8080 swerve.php`
(add `--public=html` or `--public=public` where it has static files).

- `playground/plain`: no framework, the quickstart grown to a counter at `/`, a form at
  `/form`, and a chat room at `/chat/lobby` behind a sign-in at `/login`.
- `playground/showcase`: no framework, one page with a card for each kind of event and for
  JavaScript interop, next to the source that runs it.
- `playground/slim` (Slim 4) and `playground/laravel` (Laravel and Blade): counters, a todo list
  and a chat in each framework's routes.
- `playground/demo` (mini), the largest:
  - `/live/{id}`: a page through `Tether::from()` in a mini route.
  - `/`: a single live page through the middleware: sign-in through the session, a counter, a
    clock, a todo list with keyed children, a hook talking with the server, an error boundary,
    and a button that crashes the tab.
  - `/app/`: an App: rooms in one layout, a stand-in for a call that keeps running while you
    move between rooms, redirects, and an about page with a root of its own.
  - `/interop`: every call from the server into the browser (a canvas, Promises, modules,
    helpers) and `Tether.invoke` back, one button each.
  - `/events`: elements bound to the DOM events Tether handles.
  - `/fast?rate=50`: a component updating 50 times a second.

## Production

- **Secret** (`Tether::page()` and the middleware; `from()` has none, its props stay on the
  server): the props travel through the browser, signed. Set
  `TETHER_SECRET` (a random string of at least 32 bytes) in the environment of every server;
  without it, each machine makes a key of its own in the temporary directory (private to its
  user), which is fine on one machine only. Pages open when the secret changes, or the key file is
  deleted, are refused when they connect. A key shorter than 32 bytes is an error.
  (Apps don't need it either: a tab mounts from its URL.)
- **Behind a proxy** (nginx, HAProxy, a load balancer): let WebSocket upgrades through to
  swerve (the page's own URL for `from()`, `/_tether/live` for the middleware, `.tether/live` below an App), and keep the `Host` header: the live
  endpoint compares the page's `Origin` with it. A page served from another origin must be
  listed: `origins: ['https://app.example.com']`.
- **Timeouts**: a live connection is quiet while nothing changes. Proxies that close idle
  connections after a minute make tabs reconnect (and start over): raise their tunnel timeout.
- **Capacity**: every open tab is a connection held by a worker, and holds its components in
  memory. How many connections a worker can have, with and without phasync-ext, and how many
  workers to run (four times the cores is a start for many long-lived connections), is in
  [swerve's production guide](https://github.com/phasync/swerve/blob/main/docs/production.md#sizing).
- **Restarts and deploys**: when a worker drains (a reload, a deploy), its tabs' connections
  end; the tabs reconnect to another worker and mount again. Component state that must survive
  belongs in storage.
