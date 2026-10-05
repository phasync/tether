# Tether showcase

One page, no framework: a tiny router in `swerve.php`, `Tether::from()` for the page, and one component per
card in `src/`. Each card shows its live demo next to the source that runs it, read from the real files.

```
composer install
vendor/bin/swerve --http=127.0.0.1:8080 --public=public swerve.php
```

| Card | Bindings |
|---|---|
| Pointer trail and hover | `pointermove.throttle-50`, `mouseenter.delay-300`, other tabs through `Swerve::publish()` |
| Touch pad | `touchstart/move/end`, multi-touch, pointer events for the mouse |
| Keyboard | `keydown/keypress/keyup`, `.document.mod.key-k`, `tether-ref` focus |
| Focus and validation | `focusin/focusout`, `#[Bind]`, `tether-submit`, IME-safe Enter |
| Drag and drop | `dragstart/dragenter/dragover.prevent/drop/dragend`, `tether-args` |
| Viewport | `resize`, `scroll.window`, `visibilitychange`, `online/offline`, `elementresize`, `intersect` |
| Clipboard, storage, refs | `browser()->call()`, `ref()`, `#[Invokable]` and `Tether.invoke()` from a plain script |
| Canvas | a 2D context as a `JsObject`: a few calls per event, for demos, not per-frame loops |
| JsObject | `window->fetch()` awaited with `phasync::await()`, `querySelector()` and `getBoundingClientRect()->value()` |
| Hook | `Tether.hook()` around a third-party style widget: mounted, updated, destroyed, server calls and `invoke()` |

`public/showcase.js` holds the hook and the `Tether.invoke()` script; `public/showcase.css` is all the styling.

Test: `tests/browser/showcase.mjs` drives every card with real input (CDP).
