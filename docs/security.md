# Security

Tether runs your components on the server, but the browser is not yours: anyone with the page
can send whatever the protocol allows. This is what it allows, and what is up to you.

## Handlers are public endpoints

Every public method of a component's own class is an event handler, callable by the browser
with any JSON arguments that match its parameter types, whether or not the page shows a button
for it. Treat each like a POST route:

- Check permissions **in the handler**: "the button is only shown to admins" protects nothing.
- Validate arguments: types are checked, values are not (an `int $id` can be any id).
- A handler that throws is logged in full; the browser is only told "The handler failed", so
  driver and database messages stay on the server.
- Keep everything else `private` or `protected`. Methods of `Component` itself (`render`,
  `mount`, `run`, `js`, ...) and an error boundary's `catch()` are never callable.

## Escaping

`render()` returns HTML. Everything that comes from users, the database or other services must
be escaped: `htmlspecialchars($value)` in text and in quoted attribute values. Never put user
input into `tether-*` attributes, `<script>`, `style` or URLs without checking it.

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

The older `Tether::page()` gives the root component its props differently. They go to the
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

## Limits

- A message from the browser is at most 1 MiB; larger closes the connection.
- A client that stops reading for 30 s is disconnected.
- Rendering is limited to 30 frames a second per tab, whatever the events.
- There is no rate limit on events: a handler that does expensive work for every call should
  limit itself.
