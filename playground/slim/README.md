# Tether in Slim 4

A Slim application on swerve. Each live page is one `Tether::from($request, ...)` in a route;
the controller answers both the page and the WebSocket upgrade.

```
composer install
vendor/bin/swerve --ext --http=8080 swerve.php
```

| Route | What |
|---|---|
| `/` | counter, todo list and the user (`Who`) |
| `/counter` | the counter alone |
| `/chat/{lobby,dev}` | a room shared by every tab through swerve's publish/subscribe |
| `/keys` | hover, focus/blur, `Ctrl+K` and a call into the browser (`tether-ref`, `executeString`) |

A Slim middleware sets the request attribute `user` from the `user` cookie. `Who` renders it on
the server and a handler reads `$this->request()->getAttribute('user')` on the live tab: the
middleware ran for the upgrade request too.

Tested by `node tests/browser/slim.mjs http://127.0.0.1:8080/` from the repository root.
