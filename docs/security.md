# Security

Tether runs your components on the server, but the browser is not yours: anyone with the page
can send whatever the protocol allows. This is what it allows, and what is up to you.

## Handlers are public endpoints

Every public method of a component's class, inherited ones from your own base classes and
traits included, is an event handler, callable by the browser
with any JSON arguments that match its parameter types, whether or not the page shows a button
for it. Treat each like a POST route:

- Check permissions **in the handler**: "the button is only shown to admins" protects nothing.
- Validate arguments: types are checked, values are not (an `int $id` can be any id).
- A handler that throws is logged in full; the browser is only told "The handler failed", so
  driver and database messages stay on the server.
- What a handler returns reaches the browser only when it is marked `#[Tether\Invokable]`
  (`Tether.invoke()`); that is the list of handlers whose results you have decided are public.
- `bind()` makes only `#[Bind]` properties settable, cast to their type; every other property is out of reach.
- Keep everything else `private` or `protected`, helpers in a base class too. Methods of
  `Component` itself (`render`, `mount`, `run`, `browser`, ...) and an error boundary's
  `catch()` are never callable; `bound()` is, and sets only `#[Bind]` properties.

## Escaping

`render()` returns HTML. Everything that comes from users, the database or other services must
be escaped: `$this->e($value)` (`htmlspecialchars()`) in text and in quoted attribute values. Never put user
input into `tether-*` attributes, `<script>`, `style` or URLs without checking it.

HTML that users write (a rich-text field, Markdown) is sanitized with an allowlist of tags and
attributes that leaves out every `tether-*` attribute. Without that, a visitor can put
`tether-click="deleteAccount"` in a message and make the button call a handler of the component
it lands in, in every tab that shows it. Wrapping the HTML in `tether-ignore` does not help: the
bindings inside it are read when the element is added.

## Navigation

The browser loads a URL from `navigate()` or a redirect only when it is `http:` or `https:`; a
`javascript:` URL is refused and logged to the console. Still validate a URL before you redirect
to it: an address that came from a user is an open redirect.

## Routes are public URLs

A `Tether::from()` page is a URL anyone can open, and so is its live connection. The closure is
where you decide who may see the page: it runs on every GET **and on every connection, reconnects
included**, so a redirect to sign-in or a 404 returned from it covers both (a redirect or any
other response on the connection is acted on by the browser, which loads it). Check again in the
components' handlers.

An App's routes are the same: the route code runs for a page load and for navigation over the
connection.

## Who the tab is

The live connection carries the tab's cookies, so the session is the visitor's, checked as in
any request. A component should read identity from the session (`mount()`), never from props or
arguments the browser sends.

## Props through the browser

The live tab of `Tether::from()` and of an App is mounted by running your route or closure again
for the same URL and cookies: props never go to the browser, so there is nothing to sign, and
nothing the browser sends is trusted. The tab sees the request as it was when it connected:
`request()` is a snapshot, and its body is the connection itself, so never read it.

The deprecated `Tether::page()` gives the root component its props differently. They go to the
browser in the page and come back when the tab connects, **signed** (HMAC-SHA256 with
`TETHER_SECRET`, at least 32 bytes: a shorter one is an error), so they can't be changed; they can be **read**, and replayed by the same
visitor. Don't put secrets in them; put ids in them and look things up, with permission checks,
in `mount()`.

Children's props never leave the server.

## Cross-site connections

A page on another site could open a WebSocket to yours with your visitor's cookies. The live
endpoint refuses a connection whose `Origin` is not the request's host (403), before your
closure runs; list others with `Tether::from($request, $page, origins: ['https://app.example.com'])`
(`new Tether(origins: ...)` for the middleware). Behind a proxy, the proxy must pass the original
`Host` on, or list the public origin. Non-browser clients send no `Origin` and are
let through; they have no cookies of your visitors.

## Calling the browser

`$this->browser()` runs only what the server sends: function paths and method names for `call()`,
code for `executeString()` and module URLs are yours, **never taken from input**; arguments go in as data, and
`executeString()` binds them as parameters. What the browser answers is data to check, like any
input; its errors carry the JavaScript error's name, message and stack
(`JsException`): don't show them to other users. The page registers nothing to expose: the server can reach `window` and `document` of the tabs it serves, so the server is the trust boundary
([JavaScript interop](javascript-interop.md#rules)).

## Limits

- A message from the browser is at most 1 MiB; larger closes the connection.
- A client that stops reading for 30 s is disconnected.
- Rendering is limited to 30 frames a second per tab, whatever the events.
- A tab may send 200 events a second (bursts of 400) and run 64 handlers at once; more closes
  the connection (code 4429) or refuses the call. `Tether\Limits` changes them
  ([events](events-and-javascript.md#how-events-leave-the-browser)). A handler that does
  expensive work for every call should still limit itself.
- A tab that sends nothing, not even the client's heartbeat, for 60 s is closed (code 4408;
  `Limits::$clientTimeout`).
- A tab has at most 32 calls into the browser outstanding, holds at most 4,096 of its objects, and
  a call waits 10 s for its answer (`Limits::$calls`, `$handles`, `$callTimeout`).
