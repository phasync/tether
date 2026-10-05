# Tether in Laravel

Counter, keyed todo list, swerve pub/sub chat and hover/focus/keyboard bindings, written as Blade views and served by `phasync/swerve-laravel`. Every page is one `Tether::from()` in a controller (`app/Http/Controllers/LiveController.php`).

## Run

```
composer install
cp .env.example .env
php artisan key:generate
vendor/bin/swerve --http=127.0.0.1:8080 --public=public swerve.php          # add --ext for phasync-ext
```

Open `/live` and `/chat/lobby?name=ada` (in two tabs). `/` is an ordinary Blade page. No `--ignore-platform-req` was needed (Laravel 13, PHP 8.5).

Browser test, from the repository root: `tests/browser/serve-test.sh $PWD/playground/laravel <port> public swerve.php tests/browser/laravel.mjs` (`EXT=1` for the extension).

## Blade components

`App\Live\BladeComponent` renders `resources/views/live/<kebab-class-name>.blade.php`.

- The component's properties are the view's variables, private ones included. `$component` is the component itself.
- `{!! $child(Counter::class, $props, key: 'x') !!}` places a child. Give children created in a `@foreach` a `key`.
- The view needs a single root element and nothing, not even an HTML comment, before it.
- Output is raw: escape with `{{ }}`.
- Import classes with `@use('App\Live\Counter')`.
- Props only: components are built from a props array, so pass ids and values, not models.

The layout (`resources/views/layouts/app.blade.php`) is the `shell:` closure of `from()`. It must print `$scripts` in the head and `$root` in the body.

Without `--ext`, a wait inside a Blade view shares the process's output buffer with other tabs. With `--ext`, call `Swerve::virtualize()` first.

## Spike: Laravel services in a live component

Checked against `swerve-laravel` dev-main.

### Works

- `from()` inside a controller keeps the request scope for the GET and for the `$page` closure of the WebSocket upgrade. `auth()`, `session()`, route parameters and the container are all available there; hand what a component needs over as props.
- With `enter: fn ($request, $tab) => Handler::run($tab)`, components can use `view()`, `config()`, `Cache`, `Str`, `url()` and `route()` (the latter two build from `APP_URL`) for as long as the tab is open.
- `Component::request()` is the upgrade request, with its cookies and headers.
- A tab does not hold up a graceful shutdown, and `dispose()` runs when it closes.

### Blocked, and why

- Without `enter`, the first `app()`, `view()` or facade call in a component's render, handler or `run()` throws `LogicException: Laravel was used outside a request: wrap the code in Swerve\Laravel\Handler::run()`. The live tab runs in a WebSocket callback after the request's application has been released. The tab crashes and the client reconnects in a loop.
- `Handler::run()` boots a fresh console application: no request (`request()->path()` is `/`), no session, `auth()->check()` is false. `run()` passes its closure neither the application nor a request.
- Each open tab holds one booted application until it closes.
- Session writes in a tab stay in that tab's memory. A sign-out in another request does not reach an open tab, which keeps the identity it started with.

### What swerve-laravel would need

- A supported way to run a closure in an application that carries the original request: either `Handler::run()` taking a request, or a way to keep the request's application alive for a WebSocket callback.
- A documented read-only session snapshot for that case.

### Restoring the session in the tab, in user land

Inside `enter`, rebuild the request from the upgrade's cookies, decrypt them and start the session read-only. Auth (here a custom provider) and `csrf_token()` then work in the tab:

```php
enter: fn ($psr, $tab) => Handler::run(function () use ($psr, $tab) {
    $request = Request::create('/', 'GET', [], $psr->getCookieParams());
    $request = (new EncryptCookies(app('encrypter')))->handle($request, fn ($r) => $r);
    app()->instance('request', $request);
    $session = app('session.store');
    $session->setId($request->cookies->get(config('session.cookie')));
    $session->start();
    $request->setLaravelSession($session);
    $tab();
}),
```

It is a snapshot: do not wrap the tab in `StartSession`, which would save the stale session when the tab ends and could undo a sign-out. This restore is not part of the demo and the demo's tests do not cover it.

## Notes

- `storage/framework/views` is the compile cache shared by all workers.
- Keep Eloquent and PDO out of components until the application per tab is sorted out.
