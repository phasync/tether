# State, sessions and many users

## The tab is a request

A tab's live connection starts as an HTTP request (the WebSocket upgrade, with the tab's
cookies), and Tether keeps it as mini's current request for as long as the tab is open. Every
component of the tab, in `mount()`, handlers, `run()` and the coroutines they start, sees it:

- `mini\request()`, `$_COOKIE`, `$_GET` (of the upgrade request, `/_tether/live`)
- `$_SESSION`: the visitor's session, as in any request. Reads see changes made by other
  requests (sign-out in another tab); a component may write it too, but it can't set cookies:
  a session that doesn't exist yet must be started by a normal request (a sign-in form posting
  to a mini route).
- Mini's **Scoped** services are one per tab, shared by its components: the session, the
  `PDO` connection, `mini\db()`.

So authentication is mini's: sign in through an ordinary route that writes the session, and
read it in components.

```php
final class ChatPage extends Component
{
    public string $user = '';

    public function mount(): void
    {
        $this->user = $_SESSION['user'] ?? '';
    }

    public function render(): string
    {
        if ('' === $this->user) {
            return '<form method="post" action="/login"><input name="name"> <button>Join</button></form>';
        }
        // ...
    }
}
```

Check what the tab may do where it matters, in the handler: a handler is callable by anyone who
has the page (see [Security](security.md)).

## The database

The `PDO` service is Scoped, so each open tab holds one connection for as long as it is open. For
SQLite that is free. For MySQL or PostgreSQL, 1,000 open tabs are 1,000 connections: size the
server for it, or keep connections short (open one in the handler that needs it).

The components of a tab share the connection, and their coroutines take turns: don't wait for
anything else (`sleep()`, `js()`, a subscription) inside a transaction.

## State that outlives the tab

Component properties live as long as the component: a reload, a lost connection or a deploy
starts over from `mount()`. What must last goes to storage (the database, the session, a
cache), and `mount()` reads it back.

## Many users: publish and subscribe

Each tab's components live in one worker; other tabs are in other workers. Swerve's
publish/subscribe reaches them all (see swerve's
[publish-subscribe.md](https://github.com/phasync/swerve/blob/main/docs/publish-subscribe.md)):

```php
use Swerve\Swerve;

final class Room extends Component
{
    public string $room = 'lobby';

    /** @var list<array{user: string, text: string}> */
    public array $messages = [];

    private string $user = '';

    public function mount(): void
    {
        $this->user     = $_SESSION['user'] ?? 'guest';       // who: from the session, never from the browser
        $this->messages = Message::recent($this->room, 50);   // from storage
    }

    public function run(): void
    {
        // Subscribed for as long as the component is live; cancelling run() ends it
        foreach (Swerve::subscribe("room:{$this->room}") as $json) {
            $this->messages[] = json_decode($json, true);
            $this->messages   = array_slice($this->messages, -200);
            $this->requestRender();
        }
    }

    public function send(array $form): void
    {
        $message = ['user' => $this->user, 'text' => trim($form['text'] ?? '')];
        if ('' === $message['text']) {
            return;
        }
        Message::store($this->room, $message);                          // storage first
        Swerve::publish("room:{$this->room}", json_encode($message));   // then tell everyone
    }

    public function render(): string
    {
        $items = '';
        foreach ($this->messages as $m) {
            $items .= '<li><b>' . htmlspecialchars($m['user']) . '</b> ' . htmlspecialchars($m['text']) . '</li>';
        }

        return "<section><ul>{$items}</ul><form tether-submit=\"send\"><input name=\"text\" autocomplete=\"off\"></form></section>";
    }
}
```

- The sender's own tab gets its message through the subscription too, like everyone else.
- A message published between `mount()` reading storage and `run()` subscribing is missed by
  that tab. When that matters, subscribe first and read storage after, in `run()`, and give
  messages ids to drop duplicates (swerve's docs: "State on connect, then updates").
- A subscriber that falls more than 30 s behind gets `Swerve\SubscriberLagException`, which
  fails `run()`: the nearest [error boundary](errors.md) catches it, or the tab starts over and
  reloads from storage.
- Presence (who is online), typing indicators and notifications work the same way: a topic per
  room or user, published by handlers, followed by `run()`.

## Long work and streams (an LLM, for instance)

A handler or `run()` may take as long as it needs; the tab stays responsive, since each runs in
a coroutine of its own. Update state as results arrive and call `requestRender()`: rendering
is limited to 30 frames a second, so streaming a response token by token costs nothing extra.

```php
public function ask(array $form): void
{
    $this->answer = '';
    foreach (Llm::stream($form['question']) as $token) {  // reads an HTTP stream
        $this->answer .= $token;
        $this->requestRender();
    }
}
```

The HTTP client must not block the worker: phasync's streams, or any client with phasync-ext
loaded. If the component leaves while this runs, the handler is cancelled at its next wait.
