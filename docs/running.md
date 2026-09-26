# Running Tether

A Tether application is a [mini](https://github.com/frodeborli/fubber-mini) application with
Tether's middleware, served by [swerve](https://github.com/phasync/swerve). The demo in
`playground/demo` is one; this page builds the same.

## Setting up

Tether is not on Packagist yet: install it from its repository, or from a checkout next to your
project as the demo does:

```json
{
    "require": {
        "php": ">=8.3",
        "phasync/tether": "@dev",
        "phasync/swerve": "@alpha",
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

The layout of a mini application, plus `swerve.php`:

```
_routes/index.php   the page: returns Tether::page(...)
src/                your components (App\...)
html/               public files: index.php for PHP-FPM, your .js and .css
swerve.php          the application, for swerve
```

`swerve.php` returns mini's request pipeline with Tether's middleware in front:

```php
<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Mini;
use Tether\Tether;

$dispatcher = Mini::$mini->get(RequestDispatcher::class);
$dispatcher->addMiddleware(new Tether());

return $dispatcher;
```

A route returns a page whose root is a component:

```php
<?php // _routes/index.php

return Tether\Tether::page(App\ChatPage::class, ['room' => 'lobby'], 'Chat', <<<'HTML'
    <link rel="stylesheet" href="/app.css">
    <script src="/app.js" defer></script>
    HTML);
```

`Tether::page($class, $props, $title, $head)`: the root component and its props (JSON: they
travel through the browser, signed), the page title (text), and HTML for the `<head>`: your
styles, and your scripts. Load scripts with `defer`, so they run after Tether's client and can
register hooks.

Other routes are ordinary mini routes: a login form, an upload endpoint, an API. The page's
root component can be different per route (`/room/{id}` gives `ChatPage` its room).

## Running

```
vendor/bin/swerve --http=8080 --public=html swerve.php
```

`--public=html` serves your static files. Open http://localhost:8080/. With `--watch`, swerve
restarts its workers when a PHP file changes; open tabs reconnect and mount again.

A tab's connection stays with the worker that accepted it, and each worker holds its tabs'
components in memory. Swerve starts one worker per CPU core; they share nothing but what goes
through storage or [publish/subscribe](state.md#many-users-publish-and-subscribe).

Under PHP-FPM or `php -S` (through `html/index.php`), pages render but don't go live: there is
no WebSocket. Swerve is the server for Tether.

## The demo

```
cd playground/demo
composer install
vendor/bin/swerve --http=8080 --public=html swerve.php
```

It shows sign-in through the session, a counter, a clock, a todo list with keyed children, a
hook talking with the server, an error boundary, and a button that crashes the tab. `/fast?rate=50`
is a component updating 50 times a second.

## Production

- **Secret**: the root component's props are signed. Set `TETHER_SECRET` (any long random
  string) in the environment of every server; without it, each machine makes a key of its
  own in the temporary directory, which is fine on one machine only.
- **Behind a proxy** (nginx, HAProxy, a load balancer): let WebSocket upgrades through to
  swerve on `/_tether/live`, and keep the `Host` header: the live endpoint compares the page's
  `Origin` with it. A page served from another origin must be listed:
  `new Tether(origins: ['https://app.example.com'])`.
- **Timeouts**: a live connection is quiet while nothing changes. Proxies that close idle
  connections after a minute make tabs reconnect (and start over): raise their tunnel timeout.
- **Capacity**: about 150 KB of memory per open tab with a handful of components, and about
  18,000 frames a second per core. Without phasync-ext, a worker handles about 900 connections
  (PHP's `stream_select()` limit); with it, memory is the limit.
- **Restarts and deploys**: when a worker drains (a reload, a deploy), its tabs' connections
  end; the tabs reconnect to another worker and mount again. Component state that must
  survive belongs in storage.
