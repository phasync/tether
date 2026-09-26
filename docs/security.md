# Security

Tether runs your components on the server, but the browser is not yours: anyone with the page
can send whatever the protocol allows. This is what it allows, and what is up to you.

## Handlers are public endpoints

Every public method of a component's own class is an event handler, callable by the browser
with any JSON arguments that match its parameter types, whether or not the page shows a button
for it. Treat each like a POST route:

- Check permissions **in the handler**: "the button is only shown to admins" protects nothing.
- Validate arguments: types are checked, values are not (an `int $id` can be any id).
- Keep everything else `private` or `protected`. Methods of `Component` itself (`render`,
  `mount`, `run`, `js`, ...) and an error boundary's `catch()` are never callable.

## Escaping

`render()` returns HTML. Everything that comes from users, the database or other services must
be escaped: `htmlspecialchars($value)` in text and in quoted attribute values. Never put user
input into `tether-*` attributes, `<script>`, `style` or URLs without checking it.

## Routes are public URLs

An App's routes are URLs anyone can open: check in the route who may see the page (redirect to
sign-in), and again in the components' handlers. Navigation over the connection runs the same
route code as a page load.

## Who the tab is

The live connection carries the tab's cookies, so the session is the visitor's, checked as in
any request. A component should read identity from the session (`mount()`), never from props or
arguments the browser sends.

## Props through the browser

An App's live tab mounts from its URL, through its route: nothing the browser sends is trusted.

A single live page's root component gets its props from `Tether::page()`. They go to the
browser in the page and come back when the tab connects, **signed** (HMAC-SHA256 with
`TETHER_SECRET`), so they can't be changed; they can be **read**, and replayed by the same
visitor. Don't put secrets in them; put ids in them and look things up, with permission checks,
in `mount()`.

Children's props never leave the server.

## Cross-site connections

A page on another site could open a WebSocket to yours with your visitor's cookies. The live
endpoint refuses a connection whose `Origin` is not your host (403), unless listed:
`new Tether(origins: ['https://app.example.com'])`. Non-browser clients send no `Origin` and are
let through; they have no cookies of your visitors.

## Limits

- A message from the browser is at most 1 MiB; larger closes the connection.
- A client that stops reading for 30 s is disconnected.
- Rendering is limited to 30 frames a second per tab, whatever the events.
- There is no rate limit on events: a handler that does expensive work for every call should
  limit itself.
