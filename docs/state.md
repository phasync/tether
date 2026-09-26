# State, sessions and many users

## The tab is a request

A tab's live connection starts as an HTTP request (the WebSocket upgrade, with the tab's
cookies), and stays that request for as long as the tab is open. Every component coroutine of
the tab (`mount()` once live, handlers, `run()`, `go()`) runs in that request's phasync context,
like any coroutine of a request. With `enter` (see [Running Tether](running.md)), your
framework makes it the current request.

With mini (`enter: RequestDispatcher::within(...)`):

- `mini\request()`, `$_COOKIE`, and `$_GET` of the upgrade request (not the page's URL: that
  reaches the page through its route's props).
- `$_SESSION`: the visitor's session, as in any request. Reads see changes other requests made
  (a sign-out in another tab). A component may write it too, but it can't set cookies, so a
  session is started by an ordinary request: the sign-in form.
- mini's Scoped services are one per tab, shared by its components: the session, the `PDO`
  connection, `mini\db()`.

So authentication is your framework's: sign in through an ordinary route that writes the
session, and read it in components.

## Signing in

With mini, a route file for the form to post to:

```php
<?php // _routes/login.php

use mini\Http\Message\Response;

return function () {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ('' === $name || mb_strlen($name) > 30) {
        return new Response('', ['Location' => '/login-form?error=1'], 303);
    }
    $_SESSION['user'] = $name;

    return new Response('', ['Location' => '/'], 303);   // body, headers, status
};
```

and in components:

```php
public function mount(): void
{
    $this->user = $_SESSION['user'] ?? null;
}
```

An App's route can send visitors who aren't signed in to the form:

```php
#[Route('/')]
public function home(): Page|ResponseInterface
{
    if (!isset($_SESSION['user'])) {
        return new Response('', ['Location' => '/login-form'], 302);
    }

    return new Page(Layout::class, [], 'Chat');
}
```

A redirect to a URL outside the App is a full page load, also when it happens during
navigation. Check who may do what again where it matters, in the handler: a handler is callable
by anyone who has the page (see [Security](security.md)).

## The database

With mini, `mini\db()` (or the `PDO` service) is one connection per tab, for as long as it is
open:

- Without configuration, it is SQLite, in `_database.sqlite3` at the application's root;
  `DATABASE_URL` selects another (`mysql://user:pass@host/db`).
- Rows come back as objects:
  `foreach (db()->query('SELECT id, text FROM messages WHERE room = ?', [$room]) as $row) { $row->text; }`.
  `db()->insert('messages', [...])` returns the new id (a string); `db()->exec()` runs
  statements.
- Create the schema before the workers start: a migration, or in `swerve.php` before it returns
  the application (mini's services work there). Once per start, not in every component.
- **SQLite with many workers**: they all write the same file. mini sets a busy timeout, so
  writers wait for each other instead of failing; for many writers, switch the file to WAL once
  (`PRAGMA journal_mode = WAL`; it stays set).
- **One connection per open tab**: free for SQLite; for MySQL or PostgreSQL, 1,000 open tabs are
  1,000 connections, so size the server for it.
- **Queries block the worker** while they run, with or without phasync-ext: keep them short
  (indexes), as every tab in the worker waits.
- The components of a tab share the connection and take turns: don't wait for anything
  (`sleep()`, `js()`, a subscription) inside a transaction.

## State that outlives the tab

Component properties live as long as the component: a reload, a lost connection or a deploy
starts over from `mount()`. What must last goes to storage (the database, the session), and
`mount()` reads it back.

## Many users: publish and subscribe

Each tab's components live in one worker; other tabs are in other workers. Swerve's
publish/subscribe reaches them all (see swerve's
[publish-subscribe.md](https://github.com/phasync/swerve/blob/main/docs/publish-subscribe.md)).
A chat room, with no message lost between loading and subscribing:

```php
use Swerve\Swerve;
use Tether\Component;

final class Room extends Component
{
    public int $room = 0;

    /** @var array<int, object> messages by id */
    public array $messages = [];

    private string $user = '';

    public function mount(): void
    {
        $this->user     = $_SESSION['user'] ?? 'guest';
        $this->messages = Messages::recent($this->room);            // for the first render
    }

    public function run(): void
    {
        // Subscribe first, then read storage: a message sent in between arrives twice, not never
        $subscription   = Swerve::subscribe("room:{$this->room}");
        $this->messages = Messages::recent($this->room);
        $this->requestRender();
        foreach ($subscription as $json) {
            $message                      = json_decode($json);
            $this->messages[$message->id] = $message;               // by id: a duplicate replaces itself
            $this->messages               = array_slice($this->messages, -200, preserve_keys: true);
            $this->requestRender();
        }
    }

    public function send(array $form): void
    {
        $text = trim($form['text'] ?? '');
        if ('' === $text || mb_strlen($text) > 2000) {
            return;
        }
        $message = Messages::add($this->room, $this->user, $text);  // storage first: it gets its id
        Swerve::publish("room:{$this->room}", json_encode($message));
    }

    public function render(): string
    {
        $items = '';
        foreach ($this->messages as $m) {
            $items .= '<li><b>' . htmlspecialchars($m->user) . '</b> ' . htmlspecialchars($m->text) . '</li>';
        }

        return "<section><ul>{$items}</ul><form tether-submit=\"send\"><input name=\"text\" autocomplete=\"off\"></form></section>";
    }
}

final class Messages
{
    /** @return array<int, object> the room's last 50 messages, oldest first, by id */
    public static function recent(int $room): array
    {
        $messages = [];
        foreach (mini\db()->query('SELECT id, user, text FROM messages WHERE room = ? ORDER BY id DESC LIMIT 50', [$room]) as $row) {
            $messages[$row->id] = $row;
        }

        return array_reverse($messages, preserve_keys: true);
    }

    public static function add(int $room, string $user, string $text): object
    {
        $id = (int) mini\db()->insert('messages', ['room' => $room, 'user' => $user, 'text' => $text]);

        return (object) ['id' => $id, 'user' => $user, 'text' => $text];
    }
}
```

- The sender's own tab gets its message through the subscription too, like everyone else.
- One subscription per coroutine: to follow several topics, start one coroutine each with
  `go()` (see [Components](components.md#go-more-coroutines)).
- A subscriber that falls more than 30 s behind gets `Swerve\SubscriberLagException`, which
  fails its coroutine: the nearest [error boundary](errors.md) catches it, or the tab starts
  over and reloads from storage.

## Presence: who is online

Joining and leaving aren't events the browser sends: a tab is present while its component is
live. Keep presence in storage with a heartbeat (a crashed worker never says goodbye), and
publish changes:

```php
public function run(): void
{
    $this->go(function () {                                   // the heartbeat
        while (true) {
            Presence::beat($this->room, $this->tabId, $this->user);   // upsert, seen_at = now
            phasync::sleep(10);
        }
    });
    Swerve::publish("presence:{$this->room}", 'changed');
    try {
        foreach (Swerve::subscribe("presence:{$this->room}") as $_) {
            $this->online = Presence::online($this->room, 30);  // users seen in the last 30 s
            $this->requestRender();
        }
    } finally {
        Presence::leave($this->room, $this->tabId);
        Swerve::publish("presence:{$this->room}", 'changed');
    }
}
```

A tab id (`bin2hex(random_bytes(8))` in `mount()`) tells two tabs of the same user apart. A
"typing" indicator is the same idea without storage: publish `typing:<room>` from the input
handler at most every few seconds, and let each tab forget a name it hasn't heard from for a
few seconds (a `go()` coroutine that sleeps and clears).

## Long work and streams: an LLM

A handler or coroutine may take as long as it needs; the tab stays responsive. Update state as
results arrive and call `requestRender()`: frames are limited to 30 a second, so streaming a
response token by token costs nothing extra.

The HTTP call must not block the worker. phasync's `CurlMulti::await()` runs a curl handle
cooperatively (with or without phasync-ext), and curl's write callback gets the response as it
arrives. For an OpenAI-compatible server (a local model, for instance):

```php
use phasync\Services\CurlMulti;

public string $answer = '';

private ?\Fiber $answering = null;

public function ask(array $form): void
{
    if (null !== $this->answering) {
        return;                                   // one question at a time
    }
    $this->answer    = '';
    $this->answering = $this->go(function () use ($form) {
        try {
            $buffer = '';
            $ch     = curl_init('http://127.0.0.1:8012/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST          => true,
                CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS    => json_encode([
                    'model'    => 'my-model',
                    'stream'   => true,
                    'messages' => [['role' => 'user', 'content' => $form['question']]],
                ]),
                CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer): int {
                    $buffer .= $data;                                  // server-sent events
                    while (false !== ($end = strpos($buffer, "\n\n"))) {
                        $event  = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 2);
                        if (str_starts_with($event, 'data: {')) {
                            $this->answer .= json_decode(substr($event, 6), true)['choices'][0]['delta']['content'] ?? '';
                            $this->requestRender();
                        }
                    }

                    return strlen($data);
                },
            ]);
            CurlMulti::await($ch);
        } finally {
            $this->answering = null;
            $this->requestRender();
        }
    });
}

public function stop(): void                      // <button tether-click="stop">Stop</button>
{
    if (null !== $this->answering) {
        phasync::cancel($this->answering);
    }
}
```

- `$this->go()` returns the coroutine's `Fiber`: cancelling it stops the transfer at once (the
  `finally` still runs). The component leaving the page stops it too.
- Reasoning models may stream their reasoning first, in `delta.reasoning_content`: show it
  apart, or turn it off where the server allows (some take
  `"chat_template_kwargs": {"enable_thinking": false}`).
