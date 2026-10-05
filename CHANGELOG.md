# Changelog

## 0.1.0-alpha6

Requires phasync 2.0.0-beta7 and swerve 0.1.0-beta5.

### Added
- `Tether::from($request, Closure, shell:, origins:, enter:, nonce:, version:)`: one route answers the page and its live connection. `Component::request()`, a shell seam for the layout, reconnect backoff with jitter.
- Events: one pipeline for every event kind, `tether-on-<event>.mods` over a family table (pointer, hover, touch, wheel, keys, focus, drag, clipboard, scroll, resize, intersect, online/offline, visibility), typed `Tether\Event\*EventArgs`, send/latest/throttle/debounce/serial/drop policies, `Limits` (per-tab rate, running calls, message size, `clientTimeout`).
- JavaScript interop in both directions: `$this->browser()`, `JsObject` proxy with handle release, awaitable `JsPromise`, `JsException`, `#[Invokable]` and `Tether.invoke`.
- `tether-boost`: navigation of `from()` pages without a reload (a new tab, no continuity).
- Heartbeat that finds a dead connection from both ends; a version check that reloads a page served by other code than the server's (4001).
- `bind()`/`#[Bind]`, `tether-keep`, `propsChanged()`, `dispose()`, `isLive()`, `#[NoRender]`, connection state attributes and `Tether.reconnect()`.
- `Tether\Testing\Tab`: a live tab without a browser.
- Playgrounds: plain PHP, mini, Slim, Laravel + Blade, a showcase. Docs: attributes, Blazor comparison, testing.

### Changed
- Page-side state (typed value, checked, details open) survives renders unless the server changed the attribute. A render with unchanged HTML is not sent, except after an event on a form field.
- A refused call tells the browser the reason; a failing handler shows a fixed text, never its exception.
- A `Tether::from()` page the server refuses (1008) stays static with `tether-offline`.

### Removed
- `js()` and `tether-key`: use `$this->browser()` and `tether-keydown.key-enter`.

### Deprecated
- `Tether::page()` and the middleware: removed at 1.0. `App` stays until `from()` pages can navigate over the open connection.

### Fixed
- A crash in the connection writer takes the crash path; a render that waits keeps its dirty mark; the secret is at least 32 bytes and created 0600; reconnect backoff resets only after 5 s open; non-JSON js results, IME Enter, repeated form names, checkbox and select-multiple, `nav.load` schemes, unescaped method names in the log.
