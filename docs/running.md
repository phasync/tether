# Running Tether

Tether runs on the [swerve](https://github.com/phasync/swerve) application server, in any
PSR-15 application. This page sets one up with the [mini](https://github.com/frodeborli/fubber-mini)
framework, as the demo in `playground/demo` does, and shows what differs with another framework.

## Installing

Tether is not on Packagist yet: install it from its repository.

```json
{
    "require": {
        "php": ">=8.3",
        "phasync/tether": "@dev",
        "fubber/mini": "@dev"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/phasync/tether" }
    ],
    "autoload": { "psr-4": { "App\\": "src/" } },
    "minimum-stability": "alpha",
    "prefer-stable": true
}
```

Working on Tether, swerve or mini at the same time? Use path repositories to the checkouts
instead, and require `"phasync/swerve": "@dev"` too (a path repository only has `dev-main`):

```json
"repositories": [
    { "type": "path", "url": "../phasync-tether", "options": { "symlink": true } },
    { "type": "path", "url": "../swerve", "options": { "symlink": true } },
    { "type": "path", "url": "../mini-framework", "options": { "symlink": true } }
]
```

For production, also use phasync-ext, which ships inside phasync: it makes
blocking PHP calls (`sleep()`, reads and writes on PHP streams, and so MySQL queries through
mysqlnd) give way to other coroutines instead of blocking the worker, and lifts the limit of
about 900 connections per worker. Enable it with `"extra": {"phasync": {"ext": true}}` in your
composer.json, or start swerve with `--ext` (swerve stops if it cannot load). Tether works without it, identically, as long as components wait with phasync's
functions (see [Components](components.md#run)).

## Two ways to use it

- **An App** ([Apps and navigation](apps.md)): a class with routes to live pages, and
  navigation between them over the open connection. For an application that is live
  throughout: a chat, a dashboard, an admin.
- **A single live page**: a route returns `Tether::page(Root::class, $props, $title, $head)`,
  and Tether's middleware serves the live connection. For a live part in an otherwise ordinary
  site; following a link loads the next page as usual.

## With mini

The layout of a mini application, plus `swerve.php`:

```
_routes/__DEFAULT__.php   the App: every URL not taken by another route file
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

`_routes/__DEFAULT__.php` returns the App. mini routes everything below a directory's
`__DEFAULT__.php` to it (`_routes/chat/__DEFAULT__.php` for an App at `/chat/`):

```php
<?php

return new App\Chat(enter: mini\Dispatcher\RequestDispatcher::within(...));
```

`enter` makes each live tab the work of its request for mini: `mini\request()`, `$_COOKIE`,
`$_SESSION` and mini's Scoped services are the tab's, in every component, for as long as it is
open (see [State](state.md)).

For single live pages instead, add Tether's middleware in `swerve.php` and return
`Tether::page()` from route files:

```php
$dispatcher = Mini::$mini->get(RequestDispatcher::class);
$dispatcher->addMiddleware(new Tether\Tether(enter: RequestDispatcher::within(...)));
\mini\bootstrap();   // after the middleware, if swerve.php uses mini's services below

return $dispatcher;
```

## With another framework

Anything that gives swerve a PSR-15 request handler works, provided the framework keeps request
state per request, not in globals shared by the process: swerve runs many requests, and all
open tabs, concurrently in each worker. Slim, for example:

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
components in memory. Swerve starts one worker per CPU core; they share nothing but what goes
through storage or [publish/subscribe](state.md#many-users-publish-and-subscribe).

Under PHP-FPM or `php -S`, pages render but don't go live: there is no WebSocket. Swerve is the
server for Tether.

## The demo

```
cd playground/demo
composer install
vendor/bin/swerve --http=8080 --public=html swerve.php
```

- `/`: a single live page: sign-in through the session, a counter, a clock, a todo list with
  keyed children, a hook talking with the server, an error boundary, and a button that crashes
  the tab.
- `/app/`: an App: rooms in one layout, a stand-in for a call that keeps running while you move
  between rooms, redirects, and an about page with a root of its own.
- `/fast?rate=50`: a component updating 50 times a second.

## Production

- **Secret**: a single live page's props travel through the browser, signed. Set
  `TETHER_SECRET` (a random string of at least 32 bytes) in the environment of every server;
  without it, each machine makes a key of its own in the temporary directory (private to its
  user), which is fine on one machine only. Pages open when the secret changes, or the key file is
  deleted, are refused when they connect. A key shorter than 32 bytes is an error.
  (Apps don't need it: a tab mounts from its URL.)
- **Behind a proxy** (nginx, HAProxy, a load balancer): let WebSocket upgrades through to
  swerve (`/_tether/live`, or `.tether/live` below an App), and keep the `Host` header: the live
  endpoint compares the page's `Origin` with it. A page served from another origin must be
  listed: `origins: ['https://app.example.com']`.
- **Timeouts**: a live connection is quiet while nothing changes. Proxies that close idle
  connections after a minute make tabs reconnect (and start over): raise their tunnel timeout.
- **Capacity**: about 150 KB of memory per open tab with a handful of components, and about
  18,000 frames a second per core. Without phasync-ext, a worker handles about 900 connections
  (PHP's `stream_select()` limit); with it, memory is the limit.
- **Restarts and deploys**: when a worker drains (a reload, a deploy), its tabs' connections
  end; the tabs reconnect to another worker and mount again. Component state that must survive
  belongs in storage.
