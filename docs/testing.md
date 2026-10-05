# Testing components

`Tether\Testing\Tab` is a live tab without a browser: your components are mounted and run in
the coroutines of a `phasync::run()`, and what the browser would do is a method call. It
works in any test framework; the examples are Pest.

```php
use Tether\Testing\Tab;

test('the counter counts', function () {
    Tab::mount(Counter::class, ['count' => 3], function (Tab $tab) {
        $tab->call('increment', [4]);

        expect($tab->html())->toBe('<p tether-id="c1">7</p>');
    });
});
```

`Tab::page()` takes the closure of a `Tether::from()` page instead; it runs with `$t->live`
true and must return a Page:

```php
Tab::page(fn (Tether $t) => $t->mount(Counter::class, ['count' => 3], 'Counter'), function (Tab $tab) {
    $tab->call('increment');
}, request: $request);
```

## What a Tab does

- `call($method, $args = [], $id = 'c1', $payload = [])` sends an event to component `$id`
  (the root is `c1`) and returns when the tab is idle: no handler running, nothing left to
  send. Nothing sleeps. `$payload` is what the browser says about the event, for a handler's
  `EventArgs` parameter.
- `invoke()` is `call()` for an `#[Invokable]` handler and returns what `Tether.invoke()` gives
  the browser.
- `html()` is what the browser shows: the mount HTML with every frame's patches applied. A
  component that changes state without `requestRender()` has not changed it. `html('c2')` is one
  component's element.
- `advance($seconds)` lets `run()` loops and timers work, then waits for idle.
- `$frames` are the frames sent, as the browser receives them.
- `$crashed` is what failed the tab (a handler or `run()` with no error boundary above it); after
  it `html()` throws it, and so does `invoke()`.

`assertHandlers()` parses `html()` and throws a `LogicException` naming every `tether-click`,
`tether-on-*` etc. whose handler is not one the browser may call in its component: a typo, a
private method. `call('bound', ['name', 'Ada'])` is what a `bind()` field sends.

Events are checked as the browser's are: calling something that is not a public method of the
component's class, or with arguments of the wrong types, throws `InvalidArgumentException`.
Naming a component that is not in the page throws `LogicException`, where the browser's event
would be ignored.

## Calls into the browser

A handler that calls `$this->browser()` waits for an answer. Give `Tab` a `browser` closure
that gets each op frame and returns the value, or throws a `JsException` for a JavaScript error;
without one the call waits until its timeout.

```php
Tab::mount(Sizer::class, [], function (Tab $tab) {
    expect($tab->invoke('width'))->toBe(1024);
}, browser: fn (array $op) => 1024);
```

## Request and limits

`request:` is the request `$this->request()` returns; the default is a GET of `/`. `enter:` is
as for `Tether::from()`, for a framework's current request (`RequestDispatcher::within(...)` with
mini). `limits:` takes a larger `Limits` when a test sends more events than the bucket allows;
past it the test throws.
