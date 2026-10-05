# Errors

A component fails when its `render()`, `mount()`, an event handler, `run()` or a coroutine it
started with `go()` throws. What happens next depends on what is above it.

## Error boundaries

A component implementing `Tether\ErrorBoundary` catches what fails below it: its children, their
children, and so on.

```php
use Tether\Component;
use Tether\ErrorBoundary;

final class Panel extends Component implements ErrorBoundary
{
    public ?string $error = null;

    public function catch(\Throwable $e): void
    {
        $this->error = 'Something went wrong';
    }

    public function retry(): void
    {
        $this->error = null;
    }

    public function render(): string
    {
        return null !== $this->error
            ? "<section><p>{$this->error} <button tether-click=\"retry\">Try again</button></p></section>"
            : "<section>{$this->child(Feed::class)}</section>";
    }
}
```

- `catch()` gets the exception; then the boundary renders again. Showing an error instead of
  the children unmounts them (their coroutines are cancelled); placing them again later creates
  them anew, from `mount()`.
- A boundary does not catch its own failures, and a boundary whose render fails again right
  away hands the failure to the next boundary up.
- The exception is logged either way (swerve's log, level error).
- `catch()` is not an event handler: the browser can't call it.

Put boundaries around the parts that can fail on their own: a feed that talks to another
service, a widget, an LLM panel. The rest of the page keeps working.

## Without a boundary: the tab starts over

A failure with no boundary above crashes the tab: every component is unmounted, the connection
closes, and the browser reconnects and mounts the page from scratch, as after a restart. The
user sees the page come back in its initial state (with whatever storage and the session hold).
While connected `<html>` has `tether-live`; while disconnected `tether-offline`, for a "Reconnecting…" style. When the server
crashed the tab, `tether-crashed` is set too (1011), until the reconnect:

```css
html[tether-offline] body::before { content: 'Reconnecting…'; position: fixed; inset: 0 0 auto 0; background: #fd6; text-align: center }
```

The browser waits longer after each connection (250 ms, doubling up to 30 s, with some
randomness so that many tabs do not return together), and starts over only when a connection
has stayed open for 5 s, so a page that fails right after every mount doesn't hammer the server.

A `Tether::from()` page that the server refuses with code 1008 (its closure answered with
something other than a page or a redirect, a 404 for example) stops reconnecting: the page stays
as it was, with `tether-offline` set. A closure that throws is a failed connection like any
other, retried with the growing delay; a redirect it returns makes the browser load that URL.
With the middleware, a refused connection (a page from before a deploy changed its components)
reloads the page.

On the first render (the HTTP request), a failure is an ordinary exception in the route: your
framework's error page, a 500. During navigation in an App, a route that throws makes the
browser load the URL, and see that page.

## Expected failures

Exceptions are for bugs and outages. A validation error, a missing record, a full room are
state: set a property and render it.

```php
public function send(array $form): void
{
    if (mb_strlen($form['text'] ?? '') > 2000) {
        $this->error = 'Messages are at most 2,000 characters';

        return;
    }
    // ...
}
```

A handler's exception text is not sent to the browser: an `invoke()` from the browser rejects with "The handler failed".
A failure while the tab's frames are rendered or sent (a boundary's `catch()` that throws, a value
that can't be encoded as JSON) crashes the tab like any other, and is logged.

A call into the browser that fails (the function throws or is missing, a Promise rejects, the
result can't be JSON, no answer in time) throws `Tether\JsException` (or a subclass) into the
handler: catch it where the browser may lack something (a camera, a permission); see
[JavaScript interop](javascript-interop.md).

## Logging

Tether logs through `Swerve::log()` (PSR-3), which swerve writes to its log: failures caught by
boundaries and crashes at level error, refused events (unknown handlers, wrong argument types)
at level warning, with the browser's text cut to 64 characters on one line.
