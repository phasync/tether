# Tether

Live server-side components for PHP. Each browser tab is tethered to its components' state on
the server over one connection: events run PHP methods, and the page updates with what
changed. In the spirit of Blazor Server and Phoenix LiveView, built on the
[mini](https://github.com/frodeborli/fubber-mini) framework and the
[swerve](https://github.com/phasync/swerve) application server.

> Work in progress; nothing here is usable yet.

## Plan

1. **Mini on swerve.** A plain mini HTTP application runs on swerve unmodified: every feature,
   request isolation under concurrency, a long-lived process, with and without phasync-ext.
   Mini stays independent: it runs unmodified on PHP-FPM too, and mini applications never
   call phasync or swerve directly; the only integration point is mini's service container,
   anchored on the phasync context of each request.
2. **WebSockets for swerve** (`ProtocolUpgrade` and `WebSocket`).
3. **Tether**: components and the per-tab scope, the wire protocol, the browser runtime,
   authentication and authorization at connect, state recovery when a worker drains.
4. **A realtime chat** as the first application.

## Development

Swerve and mini are installed from the sibling checkouts `../swerve` and `../mini-framework`
(Composer path repositories, symlinked), so changes to them take effect here at once.
